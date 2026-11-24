<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\Courier;
use App\Services\Couriers\DailyActivity;
use Carbon\CarbonImmutable;

/**
 * Conseil du Poste de pilotage sur la journée de livraison (validation avec règles) :
 * - « rouvrir » : la journée est fermée (heure limite passée ou fermée à la main) alors que des livreurs libres peuvent
 *   encore prendre des courses avant la fermeture du dépôt ;
 * - « fermer » : la journée est ouverte, l'heure limite approche, et les courses du jour sans livreur dépassent ce que
 *   les livreurs peuvent encore prendre.
 * Un livreur compte s'il est disponible, a donné signe de vie récemment et a encore de la place (colis, argent, temps).
 * Rien n'est fait tout seul : le dispatch décide ; un conseil écarté ne revient pas avant le lendemain.
 */
class DayAdvice
{
    /** Minutes par course quand le livreur n'a ni rythme du jour ni habitude */
    public const DEFAULT_PACE = 20;

    /** Places libres en plus des courses en attente pour conseiller de rouvrir */
    public const MIN_SPARE = 5;

    /** Conseil de fermer : seulement dans les heures avant l'heure limite (sans heure limite : après midi) */
    public const CLOSE_WINDOW_HOURS = 3;

    /** Dépôt sans heure de fermeture réglée */
    public const DEFAULT_DEPOT_CLOSE = '19:00';

    public function __construct(private readonly DeliveryDay $day, private readonly DailyActivity $activity) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Company $company): ?array
    {
        $settings = $company->dayAdvice();
        if (! $settings['enabled'] || ! $this->day->rulesApply($company)) {
            return null;
        }

        $tz = $company->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $today = $now->toDateString();
        $closesAt = $now->setTimeFromTimeString(substr((string) ($company->depot_closes_at ?: self::DEFAULT_DEPOT_CLOSE), 0, 5));
        $minutesLeft = (int) floor(($closesAt->getTimestamp() - $now->getTimestamp()) / 60);

        $capacity = $this->capacity($company, $settings, $now, $closesAt);
        $waiting = $this->day->todaysOpenOrders($company)->whereNull('delivery_courier_id')->count();
        $reason = $this->day->closedReason($company);
        $dismissed = ($company->day_advice_dismissed['date'] ?? null) === $today ? ($company->day_advice_dismissed['kinds'] ?? []) : [];

        $base = [
            'free_couriers' => count($capacity),
            'spare' => array_sum(array_column($capacity, 'spare')),
            'couriers' => array_slice(array_column($capacity, 'name'), 0, 5),
            'waiting' => $waiting,
            'depot_closes_at' => $closesAt->format('H:i'),
            'depot_close_set' => (bool) $company->depot_closes_at,
            'cutoff_time' => $company->cutoffFor(),
            'closed' => $reason !== null,
        ];

        if ($reason !== null) {
            $until = $this->until($now, $closesAt, $settings['margin_minutes']);
            $ok = $minutesLeft >= $settings['margin_minutes'] && $until !== null
                && $base['free_couriers'] >= $settings['min_couriers'] && $base['spare'] - $waiting >= self::MIN_SPARE;

            return $ok && ! in_array('reopen', $dismissed, true) ? [...$base, 'kind' => 'reopen', 'until' => $until] : null;
        }

        $cutoff = $company->cutoffFor();
        $windowStart = $cutoff
            ? $now->setTimeFromTimeString($cutoff)->subHours(self::CLOSE_WINDOW_HOURS)
            : $now->setTime(12, 0);
        $ok = $now->gte($windowStart) && $waiting > 0 && $waiting > $base['spare'];

        return $ok && ! in_array('close', $dismissed, true) ? [...$base, 'kind' => 'close'] : null;
    }

    /** Un conseil écarté ne revient pas de la journée */
    public function dismiss(Company $company, string $kind): void
    {
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
        $current = ($company->day_advice_dismissed['date'] ?? null) === $today ? ($company->day_advice_dismissed['kinds'] ?? []) : [];
        $company->forceFill(['day_advice_dismissed' => ['date' => $today, 'kinds' => array_values(array_unique([...$current, $kind]))]])->save();
    }

    /** Rouvrir la journée jusqu'à une heure donnée, pour aujourd'hui seulement */
    public function reopenUntil(Company $company, string $until): void
    {
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
        $company->forceFill([
            'deliveries_closed_on' => null,
            'deliveries_closed_at' => null,
            'cutoff_extended_on' => $today,
            'cutoff_extended_to' => $until,
        ])->save();
    }

    /**
     * Livreurs qui peuvent encore prendre des courses aujourd'hui, et combien chacun.
     *
     * @param  array{seen_minutes: int}  $settings
     * @return list<array{id: int, name: string, spare: int}>
     */
    private function capacity(Company $company, array $settings, CarbonImmutable $now, CarbonImmutable $closesAt): array
    {
        $rows = collect($this->activity->day($company)['data'])->keyBy('id');
        $couriers = Courier::withoutGlobalScopes()->whereKey($rows->keys())->where('is_available', true)
            ->get(['id', 'is_available', 'last_seen_at', 'last_location_at', 'max_parcels', 'max_cash']);
        $seenSince = now()->subMinutes($settings['seen_minutes']);
        $result = [];

        foreach ($couriers as $courier) {
            $row = $rows->get($courier->id);
            if (! $row || ! $courier->lastSignalAt()?->gte($seenSince)) {
                continue;
            }
            if ($courier->max_cash && $row['cash_in_hand'] >= $courier->max_cash) {
                continue; // doit d'abord verser son argent
            }

            $pace = max(1, (int) ($row['pace'] ?? $row['habit']['pace'] ?? self::DEFAULT_PACE));
            $start = $row['remaining'] > 0 && $row['estimated_end'] ? CarbonImmutable::parse($row['estimated_end'])->setTimezone($now->getTimezone()) : $now;
            $start = $start->lt($now) ? $now : $start;
            $spare = (int) floor(max(0, ($closesAt->getTimestamp() - $start->getTimestamp()) / 60) / $pace);
            if ($courier->max_parcels) {
                $spare = min($spare, max(0, $courier->max_parcels - max($row['remaining'], $row['parcels_in_hand'])));
            }

            if ($spare > 0) {
                $result[] = ['id' => $courier->id, 'name' => $row['name'], 'spare' => $spare];
            }
        }

        usort($result, fn ($a, $b) => $b['spare'] <=> $a['spare']);

        return $result;
    }

    /** Heure proposée : la fermeture du dépôt moins la marge, au quart d'heure ; null si trop proche */
    private function until(CarbonImmutable $now, CarbonImmutable $closesAt, int $margin): ?string
    {
        $until = $closesAt->subMinutes($margin);
        $until = $until->setTime((int) $until->format('H'), intdiv((int) $until->format('i'), 15) * 15);

        return $until->gt($now->addMinutes(15)) ? $until->format('H:i') : null;
    }
}
