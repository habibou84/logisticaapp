<?php

namespace App\Services\Shifts;

use App\Events\CourierLocationUpdated;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierLocation;
use App\Models\CourierShift;
use App\Models\CourierShiftAttempt;
use App\Models\User;
use App\Services\Couriers\CourierTracker;
use App\Services\Vehicles\VehicleChecks;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Pointage d'arrivée d'un livreur, un par jour, vérifié par le serveur (l'heure retenue est celle du serveur) :
 *  - position assez précise (Réglages › Pointage) ;
 *  - sur le lieu du jour : distance moins l'imprécision du GPS dans le rayon autorisé ;
 *  - pas trop tôt avant l'heure prévue ; après l'heure (et la tolérance), le retard est noté.
 * Chaque refus est gardé pour le contrôle. Le pointage met le livreur en service.
 * Un responsable peut pointer à la place du livreur, avec un motif.
 */
class ShiftClock
{
    public function __construct(
        private readonly ShiftPlace $places,
        private readonly ShiftSettings $settings,
    ) {}

    /** Pointage du jour du livreur, s'il a déjà pointé */
    public function today(Courier $courier): ?CourierShift
    {
        return CourierShift::withoutGlobalScopes()->where('courier_id', $courier->id)
            ->whereDate('work_date', $this->now($courier)->toDateString())->first();
    }

    /**
     * Le livreur pointe sur place.
     */
    public function clockIn(Courier $courier, ?float $lat, ?float $lng, ?int $accuracy): CourierShift
    {
        $now = $this->now($courier);
        $place = $this->places->for($courier, $now->toDateString());
        if ($place === null) {
            throw new BusinessRuleException('Pas de pointage prévu pour vous aujourd\'hui.', 'shift');
        }
        if ($this->today($courier) !== null) {
            throw new BusinessRuleException('Vous avez déjà pointé aujourd\'hui.', 'shift');
        }
        if ($place['lat'] === null || $place['lng'] === null) {
            throw new BusinessRuleException("« {$place['label']} » n'a pas encore de position sur la carte : demandez à l'équipe de vous pointer.", 'shift');
        }
        $settings = $this->settings->for(Company::query()->find($courier->company_id));

        if ($lat === null || $lng === null) {
            $this->refuse($courier, $place, 'no_position', null, null, null, null);
            throw new BusinessRuleException('Position introuvable : activez la localisation du téléphone, puis réessayez.', 'position');
        }
        $distance = (int) round(CourierLocation::distance($lat, $lng, (float) $place['lat'], (float) $place['lng']));
        if ($accuracy === null || $accuracy > $settings['min_accuracy']) {
            $this->refuse($courier, $place, 'inaccurate', $lat, $lng, $accuracy, $distance);
            $shown = $accuracy === null ? 'inconnue' : "± {$accuracy} m";
            throw new BusinessRuleException("Position trop imprécise ({$shown}, il faut ± {$settings['min_accuracy']} m) : sortez à découvert, attendez quelques secondes, puis réessayez.", 'accuracy');
        }
        /** @var CarbonImmutable|null $expected */
        $expected = $place['expected_at'];
        if ($expected !== null && $now->lt($expected->subMinutes((int) $settings['early_minutes']))) {
            $this->refuse($courier, $place, 'too_early', $lat, $lng, $accuracy, $distance);
            throw new BusinessRuleException('Trop tôt : le pointage ouvre à '.$expected->subMinutes((int) $settings['early_minutes'])->format('H:i').' (début prévu à '.$expected->format('H:i').').', 'shift');
        }
        if (max(0, $distance - $accuracy) > $place['radius']) {
            $this->refuse($courier, $place, 'too_far', $lat, $lng, $accuracy, $distance);
            throw new BusinessRuleException('Vous êtes à '.self::meters($distance)." de « {$place['label']} » : il faut être à moins de {$place['radius']} m pour pointer.", 'distance');
        }

        $shift = $this->record($courier, $place, $now, [
            'lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy, 'distance' => $distance, 'method' => 'gps',
        ], (int) $settings['late_tolerance']);
        $this->startService($courier, $lat, $lng, $accuracy);

        return $shift;
    }

    /**
     * Un responsable pointe à la place du livreur (GPS en panne, lieu sans position…), avec un motif.
     */
    public function manual(Courier $courier, User $by, string $reason, ?CarbonImmutable $at = null): CourierShift
    {
        $now = $this->now($courier);
        $at ??= $now;
        if ($at->gt($now)) {
            throw new BusinessRuleException('L\'heure d\'arrivée ne peut pas être dans le futur.', 'time');
        }
        if ($this->today($courier) !== null) {
            throw new BusinessRuleException('Ce livreur a déjà pointé aujourd\'hui.', 'shift');
        }
        $place = $this->places->for($courier, $now->toDateString()) ?? [
            'kind' => 'agency', 'billing_plan_id' => null, 'merchant_id' => null, 'routine_id' => null,
            'label' => null, 'lat' => null, 'lng' => null, 'radius' => null, 'expected_at' => null,
        ];
        $settings = $this->settings->for(Company::query()->find($courier->company_id));

        $shift = $this->record($courier, $place, $at, [
            'method' => 'manual', 'recorded_by' => $by->id, 'reason' => mb_substr(trim($reason), 0, 255),
        ], (int) $settings['late_tolerance']);
        $this->startService($courier, null, null, null);

        return $shift;
    }

    /**
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $extra
     */
    private function record(Courier $courier, array $place, CarbonImmutable $at, array $extra, int $tolerance): CourierShift
    {
        /** @var CarbonImmutable|null $expected */
        $expected = $place['expected_at'];
        $late = $expected !== null && $at->gt($expected) ? (int) floor($expected->diffInMinutes($at, true)) : 0;

        try {
            return CourierShift::withoutGlobalScopes()->create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'work_date' => $at->toDateString(),
                'kind' => $place['kind'],
                'billing_plan_id' => $place['billing_plan_id'],
                'merchant_id' => $place['merchant_id'],
                'courier_routine_id' => $place['routine_id'],
                'place_label' => $place['label'],
                'place_lat' => $place['lat'],
                'place_lng' => $place['lng'],
                'radius' => $place['radius'],
                'expected_at' => $expected?->utc(),
                'clocked_in_at' => $at->utc(),
                'late_minutes' => $late > $tolerance ? $late : 0,
                ...$extra,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('Déjà pointé aujourd\'hui.', 'shift');
        }
    }

    /**
     * Le pointage met le livreur en service, sauf si l'état des lieux du véhicule est exigé et pas encore fait.
     */
    private function startService(Courier $courier, ?float $lat, ?float $lng, ?int $accuracy): void
    {
        $courier->refresh();
        if (! $courier->is_available && ! app(VehicleChecks::class)->blocksService($courier)) {
            $courier->is_available = true;
        }
        if ($lat !== null && $lng !== null) {
            $courier->forceFill(['current_lat' => $lat, 'current_lng' => $lng, 'last_location_at' => now()]);
        }
        if (! $courier->isDirty()) {
            return;
        }
        $courier->save();
        if ($lat !== null && $lng !== null && $courier->is_available) {
            app(CourierTracker::class)->record($courier, $lat, $lng, $accuracy);
        }
        CourierLocationUpdated::dispatch($courier);
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function refuse(Courier $courier, array $place, string $reason, ?float $lat, ?float $lng, ?int $accuracy, ?int $distance): void
    {
        CourierShiftAttempt::withoutGlobalScopes()->create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'work_date' => $place['work_date'],
            'place_label' => $place['label'],
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => $accuracy,
            'distance' => $distance,
            'reason' => $reason,
            'attempted_at' => now(),
        ]);
    }

    private function now(Courier $courier): CarbonImmutable
    {
        return CarbonImmutable::now(Company::query()->find($courier->company_id)?->timezone ?: config('app.timezone'));
    }

    public static function meters(int $distance): string
    {
        return $distance >= 1000 ? str_replace('.', ',', (string) round($distance / 1000, 1)).' km' : $distance.' m';
    }
}
