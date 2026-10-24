<?php

namespace App\Services\Vehicles;

use App\Enums\AssignmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\OrderAssignment;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleCheck;
use App\Models\VehicleLog;
use App\Services\Couriers\CourierTracker;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Statistiques du parc sur une période : coûts (carburant, entretien, réparations…), litres, jours
 * d'immobilisation ; par véhicule : distance (compteur et trajet GPS), consommation et écart à sa moyenne,
 * courses faites et coût par course.
 */
class VehicleStats
{
    public function __construct(
        private readonly Fleet $fleet,
        private readonly VehicleSettings $settings,
        private readonly CourierTracker $tracker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(int $companyId, string $from, string $to): array
    {
        $s = $this->settings->for(Company::find($companyId));
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        $rows = [];
        $totals = ['cost' => 0, 'fuel' => 0, 'liters' => 0, 'maintenance' => 0, 'interventions' => 0, 'immobilized_days' => 0, 'courses' => 0];

        foreach (Vehicle::forCompany($companyId)->where('status', '!=', 'sold')->with('courier.user')->orderBy('plate')->get() as $vehicle) {
            $costs = $this->fleet->costs($vehicle, $from, $to);
            $logs = VehicleLog::forCompany($companyId)->where('vehicle_id', $vehicle->id)->whereBetween('done_on', [$from, $to]);
            $km = $this->distance($vehicle, $start, $end);
            [$courses, $gps] = $this->usage($vehicle, $start, $end);
            $consumption = $km > 0 && $costs['liters'] > 0 ? round($costs['liters'] / $km * 100, 1) : null;
            $usual = $this->usualConsumption($vehicle, $start);
            $gap = $usual && $consumption ? (int) round(($consumption - $usual) / $usual * 100) : null;
            $gpsGap = $gps > 0 && $km > 0 ? (int) round(($km - $gps) / $gps * 100) : null;

            $rows[] = [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'name' => trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')),
                'type' => $vehicle->type->value,
                'courier' => $vehicle->courier?->user?->name,
                'costs' => $costs,
                'km' => $km,
                'gps_km' => $gps,
                'gps_gap' => $gpsGap,
                'gps_alert' => $gpsGap !== null && abs($gpsGap) > $s['gps_gap_percent'],
                'consumption' => $consumption,
                'usual_consumption' => $usual,
                'consumption_gap' => $gap,
                'consumption_alert' => $gap !== null && $gap > $s['fuel_anomaly_percent'],
                'courses' => $courses,
                'cost_per_course' => $courses > 0 ? (int) round($costs['total'] / $courses) : null,
                'cost_per_km' => $km > 0 ? (int) round($costs['total'] / $km) : null,
                'immobilized_days' => (int) (clone $logs)->sum('immobilized_days'),
            ];
            $totals['cost'] += $costs['total'];
            $totals['fuel'] += $costs['fuel'];
            $totals['liters'] += $costs['liters'];
            $totals['maintenance'] += $costs['maintenance'] + $costs['repair'];
            $totals['interventions'] += (clone $logs)->whereIn('kind', ['maintenance', 'repair'])->count();
            $totals['immobilized_days'] += (int) (clone $logs)->sum('immobilized_days');
            $totals['courses'] += $courses;
        }
        $totals['cost_per_course'] = $totals['courses'] > 0 ? (int) round($totals['cost'] / $totals['courses']) : null;
        usort($rows, fn ($a, $b) => ($b['cost_per_course'] ?? -1) <=> ($a['cost_per_course'] ?? -1));

        return ['totals' => $totals, 'vehicles' => $rows, 'gps_gap_percent' => $s['gps_gap_percent'], 'fuel_anomaly_percent' => $s['fuel_anomaly_percent']];
    }

    /** Distance au compteur sur la période : écart entre le premier et le dernier relevé (états des lieux, carnet, remises) */
    private function distance(Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $readings = collect()
            ->merge(VehicleCheck::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->whereBetween('created_at', [$start, $end])->whereNotNull('odometer')->pluck('odometer'))
            ->merge(VehicleLog::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->whereBetween('done_on', [$start->toDateString(), $end->toDateString()])->whereNotNull('odometer')->pluck('odometer'))
            ->merge(VehicleAssignment::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->whereBetween('starts_at', [$start, $end])->whereNotNull('odometer_start')->pluck('odometer_start'))
            ->filter();

        return $readings->count() > 1 ? (int) ($readings->max() - $readings->min()) : 0;
    }

    /**
     * Courses terminées par ceux qui avaient le véhicule, et kilomètres de leur trajet GPS pendant ce temps.
     *
     * @return array{0: int, 1: float}
     */
    private function usage(Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $courses = 0;
        $gps = 0.0;
        $assignments = VehicleAssignment::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('starts_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $start))->get();
        foreach ($assignments as $a) {
            $from = $a->starts_at->greaterThan($start) ? $a->starts_at : $start;
            $to = $a->ends_at && $a->ends_at->lessThan($end) ? $a->ends_at : $end;
            $courses += OrderAssignment::query()->where('courier_id', $a->courier_id)->where('status', AssignmentStatus::Completed->value)
                ->whereBetween('completed_at', [$from, $to])->count();
            $courier = Courier::withoutGlobalScopes()->find($a->courier_id);
            if ($courier) {
                $gps += $this->tracker->kilometers($courier, $from, $to);
            }
        }

        return [$courses, round($gps, 1)];
    }

    /** Consommation habituelle du véhicule : les 90 jours avant la période */
    private function usualConsumption(Vehicle $vehicle, CarbonImmutable $before): ?float
    {
        $from = $before->subDays(90);
        $liters = (float) VehicleLog::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('kind', 'fuel')
            ->whereBetween('done_on', [$from->toDateString(), $before->subDay()->toDateString()])->sum('liters');
        $km = $this->distance($vehicle, $from, $before->subSecond());

        return $liters > 0 && $km > 0 ? round($liters / $km * 100, 1) : null;
    }

    /**
     * Ligne du point de la semaine : véhicules immobilisés, échéances dépassées ou proches. Vide si rien.
     */
    public function weeklyLine(int $companyId): string
    {
        if (! $this->settings->for(Company::find($companyId))['weekly_report'] || ! Vehicle::forCompany($companyId)->exists()) {
            return '';
        }
        $alerts = collect($this->fleet->alerts($companyId));
        $stopped = $alerts->where('kind', 'status')->count();
        $expired = $alerts->where('state', 'expired')->where('kind', '!=', 'status')->count();
        $soon = $alerts->where('state', 'soon')->count();
        $late = $alerts->where('kind', 'lease')->sum('amount');
        $parts = array_filter([
            $stopped ? "{$stopped} véhicule(s) immobilisé(s)" : null,
            $expired ? "{$expired} échéance(s) dépassée(s)" : null,
            $soon ? "{$soon} à venir" : null,
            $late ? 'location-vente en retard : '.Money::format($late) : null,
        ]);

        return $parts ? 'Véhicules : '.implode(', ', $parts) : '';
    }
}
