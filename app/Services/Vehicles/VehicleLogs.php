<?php

namespace App\Services\Vehicles;

use App\Enums\EarningType;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Carnet d'un véhicule : entretien (remet à zéro les échéances du plan faites), réparation, sinistre, amende,
 * plein de carburant, autre dépense. Effets sur la paie, au choix de l'administrateur :
 *  - amende retenue au livreur qui avait le véhicule ce jour-là ;
 *  - part des dégâts d'un sinistre retenue au livreur responsable, dans la limite d'un plafond par mois ;
 *  - plein remboursé (mode remboursement), ou dépassement de la dotation du jour remboursé (mode dotation).
 */
class VehicleLogs
{
    public function __construct(
        private readonly Fleet $fleet,
        private readonly VehicleSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $photos
     */
    public function add(?User $actor, Vehicle $vehicle, array $data, array $photos = []): VehicleLog
    {
        $kind = $data['kind'];
        if (! array_key_exists($kind, VehicleLog::KINDS)) {
            throw new BusinessRuleException('Type de dépense inconnu.', 'kind');
        }
        $s = $this->settings->for(Company::find($vehicle->company_id));
        $doneOn = CarbonImmutable::parse($data['done_on'] ?? today());
        if ($kind === 'fuel' && $s['fuel_receipt_required'] && $photos === [] && $actor?->isCourier()) {
            throw new BusinessRuleException('Photographiez le reçu du plein.', 'photos');
        }
        // Livreur concerné : celui indiqué, sinon celui qui avait le véhicule ce jour-là
        $courierId = $data['courier_id'] ?? $this->fleet->holderAt($vehicle, $doneOn->endOfDay());

        return DB::transaction(function () use ($actor, $vehicle, $data, $photos, $kind, $s, $doneOn, $courierId) {
            $log = VehicleLog::create([
                'company_id' => $vehicle->company_id,
                'vehicle_id' => $vehicle->id,
                'courier_id' => $courierId,
                'kind' => $kind,
                'done_on' => $doneOn->toDateString(),
                'odometer' => $data['odometer'] ?? null,
                'label' => $data['label'] ?? null,
                'garage' => $data['garage'] ?? null,
                'amount' => (int) ($data['amount'] ?? 0),
                'liters' => $data['liters'] ?? null,
                'paid_by' => $data['paid_by'] ?? ($actor?->isCourier() ? 'courier' : 'company'),
                'courier_share' => isset($data['courier_share']) ? (int) $data['courier_share'] : null,
                'plan_keys' => $data['plan_keys'] ?? null,
                'immobilized_days' => $data['immobilized_days'] ?? null,
                'field_event_id' => $data['field_event_id'] ?? null,
                'photos' => array_map(fn (UploadedFile $f) => $f->store("vehicles/{$vehicle->id}", 'local'), $photos),
                'created_by' => $actor?->id,
            ]);

            $vehicle->readOdometer($log->odometer);
            if ($kind === 'maintenance' && $log->plan_keys) {
                $vehicle->maintenance_plan = array_map(fn ($item) => in_array($item['key'], $log->plan_keys, true)
                    ? [...$item, 'last_km' => $log->odometer ?? $vehicle->odometer, 'last_on' => $log->done_on->toDateString()] : $item,
                    $vehicle->maintenance_plan ?? []);
                $vehicle->maintenance_reminded = array_diff_key($vehicle->maintenance_reminded ?? [], array_flip($log->plan_keys));
            }
            $vehicle->save();
            if (! empty($data['status'])) {
                $this->fleet->setStatus($vehicle, $data['status']);
            }

            $courier = $courierId ? Courier::withoutGlobalScopes()->find($courierId) : null;
            $charged = match (true) {
                $courier === null => 0,
                $kind === 'fine' && $s['fines_charge_courier'] && ($data['charge_courier'] ?? true) => $this->charge($courier, $log, $log->courier_share ?? $log->amount, 'Amende', $actor),
                $kind === 'claim' && $s['damage_charge_courier'] && $log->courier_share => $this->charge($courier, $log, $this->underCap($courier, $log->courier_share, $s, $doneOn), 'Part des dégâts', $actor),
                $kind === 'fuel' && $log->paid_by === 'courier' => $this->refundFuel($courier, $log, $s, $actor),
                default => 0,
            };
            if ($charged) {
                $log->forceFill(['charged' => $charged])->save();
            }

            return $log;
        });
    }

    /** Retenue sur la paie (négative) */
    private function charge(Courier $courier, VehicleLog $log, int $amount, string $what, ?User $actor): int
    {
        if ($amount <= 0) {
            return 0;
        }
        $log->loadMissing('vehicle');
        CourierEarning::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'type' => EarningType::VehicleCharge,
            'amount' => -$amount,
            'description' => "{$what} · {$log->vehicle?->plate} du ".$log->done_on->format('d/m').($log->label ? " · {$log->label}" : ''),
            'created_by' => $actor?->id,
        ]);

        return $amount;
    }

    /** Part des dégâts dans la limite du plafond du mois (ce qui a déjà été retenu ce mois-ci compte) */
    private function underCap(Courier $courier, int $share, array $s, CarbonImmutable $on): int
    {
        if (! $s['damage_cap_month']) {
            return $share;
        }
        $already = (int) VehicleLog::withoutGlobalScopes()->where('courier_id', $courier->id)->where('kind', 'claim')
            ->whereBetween('done_on', [$on->startOfMonth()->toDateString(), $on->endOfMonth()->toDateString()])->sum('charged');

        return max(0, min($share, $s['damage_cap_month'] - $already));
    }

    /**
     * Plein payé par le livreur : remboursé en entier (mode remboursement), ou ce qui dépasse la dotation du jour
     * (mode dotation, si l'entreprise le veut). Rien si le carburant est à sa charge.
     */
    private function refundFuel(Courier $courier, VehicleLog $log, array $s, ?User $actor): int
    {
        $amount = match ($s['fuel_mode']) {
            'refund' => $log->amount,
            'allowance' => $s['fuel_allowance_excess_refund'] ? $this->excess($courier, $log, $s) : 0,
            default => 0,
        };
        if ($amount <= 0) {
            return 0;
        }
        $log->loadMissing('vehicle');
        CourierEarning::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'type' => EarningType::FuelRefund,
            'amount' => $amount,
            'description' => 'Plein '.$log->vehicle?->plate.' du '.$log->done_on->format('d/m').' ('.Money::format($log->amount).')',
            'created_by' => $actor?->id,
        ]);

        return $amount;
    }

    /** Pleins du jour au-delà de la dotation, moins ce qui a déjà été remboursé ce jour-là */
    private function excess(Courier $courier, VehicleLog $log, array $s): int
    {
        $day = VehicleLog::withoutGlobalScopes()->where('courier_id', $courier->id)->where('kind', 'fuel')
            ->whereDate('done_on', $log->done_on->toDateString())->where('paid_by', 'courier');

        return max(0, (int) (clone $day)->sum('amount') - $s['fuel_allowance'] - (int) (clone $day)->whereKeyNot($log->id)->sum('charged'));
    }

    /**
     * Suppression d'une ligne du carnet : ce qui avait été retenu ou remboursé est annulé sur la paie.
     */
    public function delete(?User $actor, VehicleLog $log): void
    {
        DB::transaction(function () use ($actor, $log) {
            if ($log->charged && $log->courier_id) {
                $refund = $log->kind === 'fuel';
                CourierEarning::create([
                    'company_id' => $log->company_id,
                    'courier_id' => $log->courier_id,
                    'type' => $refund ? EarningType::FuelRefund : EarningType::VehicleCharge,
                    'amount' => $refund ? -$log->charged : $log->charged,
                    'description' => 'Annulation · '.(VehicleLog::KINDS[$log->kind] ?? $log->kind).' du '.$log->done_on->format('d/m'),
                    'created_by' => $actor?->id,
                ]);
            }
            $log->delete();
        });
    }
}
