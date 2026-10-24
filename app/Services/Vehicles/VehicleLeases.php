<?php

namespace App\Services\Vehicles;

use App\Enums\EarningType;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLease;
use App\Models\VehicleLeasePayment;
use App\Notifications\VehicleAlert;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Location-vente et location des véhicules : montant par jour (6 ou 7 jours sur 7), semaine ou mois ; ce qui est
 * dû à une date, ce qui est payé, le retard et le reste à payer. Retenue sur la paie (chaque jour, ce qui est dû
 * devient une retenue) ou paiement en caisse. Prix total atteint : le livreur devient propriétaire du véhicule.
 */
class VehicleLeases
{
    public function __construct(
        private readonly Fleet $fleet,
        private readonly VehicleSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, Vehicle $vehicle, Courier $courier, array $data): VehicleLease
    {
        if (VehicleLease::where('vehicle_id', $vehicle->id)->where('status', 'active')->exists()) {
            throw new BusinessRuleException('Ce véhicule a déjà une location en cours.', 'vehicle_id');
        }
        if (($data['kind'] ?? 'purchase') === 'purchase' && empty($data['total_amount'])) {
            throw new BusinessRuleException('Indiquez le prix total de la location-vente.', 'total_amount');
        }

        return DB::transaction(function () use ($actor, $vehicle, $courier, $data) {
            $lease = VehicleLease::create([
                'company_id' => $vehicle->company_id,
                'vehicle_id' => $vehicle->id,
                'courier_id' => $courier->id,
                'kind' => $data['kind'] ?? 'purchase',
                'amount' => (int) $data['amount'],
                'period' => $data['period'] ?? 'day',
                'days_per_week' => (int) ($data['days_per_week'] ?? 7),
                'total_amount' => isset($data['total_amount']) ? (int) $data['total_amount'] : null,
                'starts_on' => $data['starts_on'] ?? today()->toDateString(),
                'payment' => $data['payment'] ?? 'payroll',
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
            ]);
            $vehicle->forceFill(['ownership' => $lease->kind === 'purchase' ? 'lease' : 'rental'])->save();
            if ($vehicle->courier_id !== $courier->id) {
                $this->fleet->assign($actor, $vehicle, $courier);
            }

            return $lease;
        });
    }

    /** Nombre de versements commencés jusqu'à cette date (incluse) */
    public function periods(VehicleLease $lease, CarbonInterface $date): int
    {
        $start = CarbonImmutable::parse($lease->starts_on)->startOfDay();
        $date = CarbonImmutable::instance($date)->startOfDay();
        if ($date->lt($start)) {
            return 0;
        }

        return match ($lease->period) {
            'week' => intdiv((int) $start->diffInDays($date), 7) + 1,
            'month' => (int) $start->diffInMonths($date) + 1,
            default => $this->workingDays($start, $date, $lease->days_per_week),
        };
    }

    /** Jours payants entre deux dates incluses : 7 sur 7, sans le dimanche (6), sans le week-end (5) */
    private function workingDays(CarbonImmutable $from, CarbonImmutable $to, int $perWeek): int
    {
        $days = (int) $from->diffInDays($to) + 1;
        if ($perWeek >= 7) {
            return $days;
        }
        $count = 0;
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $dow = $d->dayOfWeekIso;
            $count += ($perWeek === 6 ? $dow !== 7 : $dow < 6) ? 1 : 0;
        }

        return $count;
    }

    public function dueAt(VehicleLease $lease, CarbonInterface $date): int
    {
        $due = $this->periods($lease, $date) * $lease->amount;

        return $lease->total_amount ? min($due, $lease->total_amount) : $due;
    }

    /**
     * @return array{paid: int, due: int, late: int, late_periods: int, remaining: ?int, progress: ?int, ends_on: ?string, periods_total: ?int, periods_paid: int}
     */
    public function balance(VehicleLease $lease, ?CarbonInterface $date = null): array
    {
        $date = CarbonImmutable::instance($date ?? today());
        $paid = (int) VehicleLeasePayment::withoutGlobalScopes()->where('vehicle_lease_id', $lease->id)->sum('amount');
        // Retard : ce qui était dû jusqu'à hier
        $late = $lease->status === 'active' ? max(0, $this->dueAt($lease, $date->subDay()) - $paid) : 0;
        $total = $lease->total_amount;
        $periodsTotal = $total ? (int) ceil($total / max(1, $lease->amount)) : null;

        return [
            'paid' => $paid,
            'due' => $this->dueAt($lease, $date),
            'late' => $late,
            'late_periods' => (int) ceil($late / max(1, $lease->amount)),
            'remaining' => $total ? max(0, $total - $paid) : null,
            'progress' => $total ? (int) min(100, floor(100 * $paid / $total)) : null,
            'ends_on' => $periodsTotal ? $this->endDate($lease, $periodsTotal) : null,
            'periods_total' => $periodsTotal,
            'periods_paid' => intdiv($paid, max(1, $lease->amount)),
        ];
    }

    /** Date du dernier versement prévu */
    private function endDate(VehicleLease $lease, int $periods): string
    {
        $start = CarbonImmutable::parse($lease->starts_on);
        if ($lease->period === 'week') {
            return $start->addWeeks($periods - 1)->toDateString();
        }
        if ($lease->period === 'month') {
            return $start->addMonths($periods - 1)->toDateString();
        }
        $d = $start;
        for ($n = $this->periods($lease, $d); $n < $periods; $d = $d->addDay(), $n = $this->periods($lease, $d)) {
            // jour suivant
        }

        return $d->toDateString();
    }

    public function pay(?User $actor, VehicleLease $lease, int $amount, string $method = 'cash', ?string $paidOn = null, ?string $note = null, ?int $earningId = null): VehicleLeasePayment
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('Montant du versement à indiquer.', 'amount');
        }
        if ($lease->status !== 'active') {
            throw new BusinessRuleException('Cette location est terminée.', 'lease');
        }
        $payment = VehicleLeasePayment::create([
            'company_id' => $lease->company_id,
            'vehicle_lease_id' => $lease->id,
            'amount' => $amount,
            'paid_on' => $paidOn ?? today()->toDateString(),
            'method' => $method,
            'courier_earning_id' => $earningId,
            'note' => $note,
            'created_by' => $actor?->id,
        ]);
        $this->completeIfPaid($lease);

        return $payment;
    }

    /**
     * Retenue sur la paie : ce qui est dû jusqu'à cette date et pas encore payé devient une retenue du livreur.
     */
    public function accrue(VehicleLease $lease, ?CarbonInterface $date = null): int
    {
        if ($lease->status !== 'active' || $lease->payment !== 'payroll') {
            return 0;
        }
        $date = CarbonImmutable::instance($date ?? today());
        $missing = $this->dueAt($lease, $date) - (int) VehicleLeasePayment::withoutGlobalScopes()->where('vehicle_lease_id', $lease->id)->sum('amount');
        if ($missing <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($lease, $missing, $date) {
            $lease->loadMissing('vehicle');
            $earning = CourierEarning::create([
                'company_id' => $lease->company_id,
                'courier_id' => $lease->courier_id,
                'type' => EarningType::VehicleLease,
                'amount' => -$missing,
                'description' => ($lease->kind === 'purchase' ? 'Location-vente' : 'Location').' '.$lease->vehicle?->plate.' jusqu\'au '.$date->format('d/m'),
                'period_start' => $date->toDateString(),
                'period_end' => $date->toDateString(),
            ]);
            $this->pay(null, $lease, $missing, 'payroll', $date->toDateString(), 'Retenue sur la paie', $earning->id);

            return $missing;
        });
    }

    private function completeIfPaid(VehicleLease $lease): void
    {
        if (! $lease->total_amount || $this->balance($lease)['paid'] < $lease->total_amount) {
            return;
        }
        $lease->forceFill(['status' => 'completed', 'late_level' => 0])->save();
        $vehicle = Vehicle::withoutGlobalScopes()->find($lease->vehicle_id);
        if ($lease->kind === 'purchase' && $vehicle) {
            // Prix total payé : le véhicule est au livreur
            $vehicle->forceFill(['ownership' => 'courier', 'owner_courier_id' => $lease->courier_id])->save();
            $courier = Courier::withoutGlobalScopes()->with('user')->find($lease->courier_id);
            $courier?->user?->notify(new VehicleAlert('lease_completed', "🎉 Le véhicule {$vehicle->plate} est à vous", 'Tous les versements de la location-vente sont faits.', '/livreur/vehicule', 'note'));
        }
    }

    /**
     * Rappels de retard : au livreur après n jours, au responsable après n jours ; remis à zéro à jour.
     */
    public function remindLate(VehicleLease $lease): int
    {
        $s = $this->settings->for(Company::find($lease->company_id));
        $b = $this->balance($lease);
        if ($b['late'] === 0) {
            if ($lease->late_level) {
                $lease->forceFill(['late_level' => 0])->save();
            }

            return 0;
        }
        $lease->loadMissing(['vehicle', 'courier.user']);
        $days = $lease->period === 'day' ? $b['late_periods'] : $b['late_periods'] * ($lease->period === 'week' ? 7 : 30);
        $sent = 0;
        if ($lease->late_level < 1 && $days >= $s['lease_late_courier_days']) {
            $lease->courier?->user?->notify(new VehicleAlert('lease_late', '💸 Versement en retard', 'Il manque '.Money::format($b['late']).' sur votre '.($lease->kind === 'purchase' ? 'location-vente' : 'location')." ({$lease->vehicle?->plate}).", '/livreur/vehicule', 'warning'));
            $lease->forceFill(['late_level' => 1])->save();
            $sent++;
        }
        if ($lease->late_level < 2 && $days >= $s['lease_late_manager_days']) {
            $users = User::forCompany($lease->company_id)->where('status', 'active')->role([Role::Admin->value, Role::ActivityManager->value])->get();
            Notification::send($users, new VehicleAlert('lease_late', "💸 Location en retard : {$lease->courier?->user?->name}", Money::format($b['late'])." de retard sur {$lease->vehicle?->plate}.", "/admin/vehicules/{$lease->vehicle_id}?onglet=location", 'alert'));
            $lease->forceFill(['late_level' => 2])->save();
            $sent++;
        }

        return $sent;
    }
}
