<?php

namespace App\Services\Shifts;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierShift;
use App\Models\CourierShiftAttempt;
use App\Models\User;
use App\Notifications\ShiftMissingAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Pointages d'un jour, pour l'équipe : pour chaque livreur actif, le lieu et l'heure attendus, son pointage
 * (heure, retard, distance, manuel ou non) et ses tentatives refusées.
 * États : on_time (à l'heure), late (en retard), waiting (pas encore l'heure), missing (l'heure est passée,
 * pas de pointage), none (pas de pointage prévu ce jour-là, sans pointage).
 * Le dispatch est prévenu des livreurs qui n'ont pas pointé après le délai choisi (une fois par livreur et par jour).
 */
class ShiftBoard
{
    public function __construct(
        private readonly ShiftPlace $places,
        private readonly ShiftSettings $settings,
    ) {}

    /**
     * @return array{date: string, is_today: bool, settings: array<string, mixed>, rows: Collection<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function day(Company $company, ?string $date = null): array
    {
        $tz = $company->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $day = $date ?: $now->toDateString();
        $settings = $this->settings->for($company);

        $couriers = Courier::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))->with('user')->get();
        $shifts = CourierShift::withoutGlobalScopes()->where('company_id', $company->id)->whereDate('work_date', $day)
            ->with('recorder:id,name')->get()->keyBy('courier_id');
        $attempts = CourierShiftAttempt::withoutGlobalScopes()->where('company_id', $company->id)->whereDate('work_date', $day)
            ->orderByDesc('attempted_at')->get()->groupBy('courier_id');
        $places = $this->places->forMany($couriers, $day);

        $rows = $couriers->map(function (Courier $courier) use ($places, $shifts, $attempts, $now, $tz) {
            $place = $places[$courier->id] ?? null;
            /** @var CourierShift|null $shift */
            $shift = $shifts->get($courier->id);
            $tries = $attempts->get($courier->id, collect());
            $expected = $place['expected_at'] ?? null;

            $state = match (true) {
                $shift !== null => $shift->late_minutes > 0 ? 'late' : 'on_time',
                $place === null => 'none',
                $expected !== null && $now->gt($expected) => 'missing',
                $expected === null && $now->toDateString() > $place['work_date'] => 'missing',
                default => 'waiting',
            };

            return [
                'courier_id' => $courier->id,
                'name' => (string) $courier->user?->name,
                'phone' => $courier->user?->phone,
                'is_available' => $courier->is_available,
                'state' => $state,
                'place' => $place ? [
                    'kind' => $place['kind'],
                    'label' => $place['label'],
                    'address' => $place['address'],
                    'merchant' => $place['merchant'],
                    'routine' => $place['routine'],
                    'located' => $place['lat'] !== null && $place['lng'] !== null,
                    'expected_at' => $expected?->toIso8601String(),
                ] : null,
                'late_now' => $state === 'missing' && $expected !== null ? (int) floor($expected->diffInMinutes($now, true)) : null,
                'shift' => $shift?->brief(),
                'attempts' => $tries->take(5)->map(fn (CourierShiftAttempt $a) => [
                    'at' => $a->attempted_at->setTimezone($tz)->format('H:i'),
                    'reason' => $a->reason,
                    'reason_label' => CourierShiftAttempt::REASONS[$a->reason] ?? $a->reason,
                    'distance' => $a->distance,
                    'accuracy' => $a->accuracy,
                ])->values()->all(),
                'attempts_count' => $tries->count(),
            ];
        })->sortBy(fn (array $r) => [array_search($r['state'], ['missing', 'late', 'waiting', 'on_time', 'none'], true), $r['place']['expected_at'] ?? '9', $r['name']])->values();

        return [
            'date' => $day,
            'is_today' => $day === $now->toDateString(),
            'settings' => array_intersect_key($settings, array_flip(['radius', 'min_accuracy', 'late_tolerance', 'alert_minutes', 'agency_name'])) + [
                'agency_located' => $settings['agency_lat'] !== null,
            ],
            'rows' => $rows,
            'counts' => collect(['on_time', 'late', 'waiting', 'missing', 'none'])->mapWithKeys(fn ($s) => [$s => $rows->where('state', $s)->count()])->all(),
        ];
    }

    /**
     * Livreurs qui n'ont pas pointé après le délai : une alerte groupée au dispatch.
     *
     * @return int livreurs signalés
     */
    public function alert(): int
    {
        $sent = 0;
        Company::query()->where('status', 'active')->each(function (Company $company) use (&$sent) {
            $settings = $this->settings->for($company);
            if (! $settings['enabled'] || (int) $settings['alert_minutes'] === 0) {
                return;
            }
            $now = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
            $board = $this->day($company);
            $late = $board['rows']->filter(fn (array $r) => $r['state'] === 'missing' && $r['late_now'] !== null
                && $r['late_now'] >= (int) $settings['alert_minutes']
                && Cache::add("shift-alert:{$r['courier_id']}:{$board['date']}", true, $now->endOfDay()));
            if ($late->isEmpty()) {
                return;
            }
            $users = User::forCompany($company->id)->where('status', 'active')
                ->role([Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value])->get();
            if ($users->isNotEmpty()) {
                Notification::send($users, new ShiftMissingAlert($late->values()->all()));
            }
            $sent += $late->count();
        });

        return $sent;
    }
}
