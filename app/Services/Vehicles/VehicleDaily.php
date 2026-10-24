<?php

namespace App\Services\Vehicles;

use App\Enums\AssignmentStatus;
use App\Enums\CompanyStatus;
use App\Enums\EarningType;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Models\VehicleLease;
use App\Notifications\VehicleAlert;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Tâche du matin des véhicules, pour chaque entreprise :
 *  - rappels des papiers (aux seuils choisis, puis à la date passée) et de l'entretien à prévoir ;
 *  - location-vente et location : retenue sur la paie de ce qui est dû, rappels de retard ;
 *  - dotation carburant et indemnité « véhicule personnel » de la veille, par jour travaillé ;
 *  - véhicules pris pour la journée et pas rendus : rendus.
 */
class VehicleDaily
{
    public function __construct(
        private readonly Fleet $fleet,
        private readonly VehicleLeases $leases,
        private readonly VehicleSettings $settings,
    ) {}

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $done = ['documents' => 0, 'maintenance' => 0, 'leases' => 0, 'allowances' => 0, 'released' => 0];
        Company::query()->where('status', CompanyStatus::Active->value)->each(function (Company $company) use (&$done) {
            foreach ($this->forCompany($company) as $key => $n) {
                $done[$key] += $n;
            }
        });

        return $done;
    }

    /**
     * @return array<string, int>
     */
    public function forCompany(Company $company): array
    {
        $s = $this->settings->for($company);
        $tz = $company->timezone ?: config('app.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        return [
            'documents' => $this->documents($company, $s),
            'maintenance' => $this->maintenance($company, $s),
            'leases' => $s['leases'] ? $this->leases($company) : 0,
            'allowances' => $this->allowances($company, $s, $today->subDay()),
            'released' => $this->release($company, $today),
        ];
    }

    private function managers(int $companyId)
    {
        return User::forCompany($companyId)->where('status', 'active')->role([Role::Admin->value, Role::ActivityManager->value])->get();
    }

    /** Papiers : niveau 1, 2… à chaque seuil franchi (30 j, 7 j), puis date passée ; un papier renouvelé repart de zéro */
    private function documents(Company $company, array $s): int
    {
        $thresholds = $s['reminder_days'] ?: [];
        rsort($thresholds);
        $sent = 0;
        $docs = VehicleDocument::forCompany($company->id)->whereNotNull('ends_on')
            ->whereDate('ends_on', '<=', now()->addDays(max($thresholds ?: [0]))->toDateString())
            ->with(['vehicle', 'courier.user'])->get();

        foreach ($docs as $doc) {
            // Remplacé par un papier plus récent de la même sorte : rien à rappeler
            $newer = VehicleDocument::forCompany($company->id)->where('kind', $doc->kind)->where('id', '!=', $doc->id)
                ->where(fn ($q) => $doc->vehicle_id ? $q->where('vehicle_id', $doc->vehicle_id) : $q->where('courier_id', $doc->courier_id))
                ->whereDate('ends_on', '>', $doc->ends_on->toDateString())->exists();
            if ($newer || ($doc->kind === 'license' && ! $s['track_license'])) {
                continue;
            }
            $days = (int) today()->diffInDays($doc->ends_on, false);
            $level = $days < 0 ? count($thresholds) + 1 : count(array_filter($thresholds, fn ($t) => $days <= $t));
            if ($level <= $doc->reminded) {
                continue;
            }
            $label = VehicleCatalog::DOCUMENTS[$doc->kind]['label'] ?? ($doc->label ?: 'Papier');
            $who = $doc->vehicle?->plate ?? $doc->courier?->user?->name;
            $when = $days < 0 ? 'dépassé depuis le '.$doc->ends_on->format('d/m') : ($days === 0 ? 'se termine aujourd\'hui' : "se termine dans {$days} jour(s) ({$doc->ends_on->format('d/m')})");
            $href = $doc->vehicle_id ? "/admin/vehicules/{$doc->vehicle_id}?onglet=papiers" : '/admin/vehicules/echeances';
            Notification::send($this->managers($company->id), new VehicleAlert('document', "📄 {$label} · {$who}", ucfirst($when).'.', $href, $days < 0 ? 'alert' : 'warning'));
            // Le livreur aussi : son permis, ou les papiers du véhicule qu'il conduit
            $courierUser = $doc->courier?->user ?? ($doc->vehicle?->courier_id ? Courier::withoutGlobalScopes()->with('user')->find($doc->vehicle->courier_id)?->user : null);
            $courierUser?->notify(new VehicleAlert('document', "📄 {$label} · {$who}", ucfirst($when).'.', '/livreur/vehicule', 'warning'));
            $doc->forceFill(['reminded' => $level])->save();
            $sent++;
        }

        return $sent;
    }

    /** Entretien : un rappel quand une échéance approche, un autre quand elle est atteinte */
    private function maintenance(Company $company, array $s): int
    {
        $sent = 0;
        foreach (Vehicle::forCompany($company->id)->whereNotIn('status', ['sold', 'out'])->get() as $vehicle) {
            $reminded = $vehicle->maintenance_reminded ?? [];
            foreach ($this->fleet->maintenance($vehicle, $s) as $item) {
                if ($item['state'] === 'ok' || ($reminded[$item['key']] ?? null) === $item['state'] || (($reminded[$item['key']] ?? null) === 'due')) {
                    continue;
                }
                $left = $item['state'] === 'due' ? 'à faire maintenant' : collect([
                    $item['remaining_km'] !== null ? "dans {$item['remaining_km']} km" : null,
                    $item['remaining_days'] !== null ? "dans {$item['remaining_days']} jour(s)" : null,
                ])->filter()->implode(' ou ');
                Notification::send($this->managers($company->id), new VehicleAlert('maintenance', "{$item['icon']} {$item['label']} · {$vehicle->plate}", ucfirst($left).'.', "/admin/vehicules/{$vehicle->id}?onglet=entretien"));
                if ($vehicle->courier_id) {
                    Courier::withoutGlobalScopes()->with('user')->find($vehicle->courier_id)?->user
                        ?->notify(new VehicleAlert('maintenance', "{$item['icon']} {$item['label']} {$left}", "Votre véhicule {$vehicle->plate} : prévenez l'agence pour le garage.", '/livreur/vehicule'));
                }
                $reminded[$item['key']] = $item['state'];
                $sent++;
            }
            if ($reminded !== ($vehicle->maintenance_reminded ?? [])) {
                $vehicle->forceFill(['maintenance_reminded' => $reminded])->save();
            }
        }

        return $sent;
    }

    private function leases(Company $company): int
    {
        $n = 0;
        foreach (VehicleLease::forCompany($company->id)->where('status', 'active')->get() as $lease) {
            $n += $this->leases->accrue($lease) > 0 ? 1 : 0;
            $n += $this->leases->remindLate($lease->fresh());
        }

        return $n;
    }

    /**
     * Dotation carburant (mode dotation) et indemnité « véhicule personnel » de la veille : pour chaque livreur qui a
     * travaillé ce jour-là (au moins une mission terminée), une fois.
     */
    private function allowances(Company $company, array $s, CarbonImmutable $day): int
    {
        $fuel = $s['fuel_mode'] === 'allowance' ? (int) $s['fuel_allowance'] : 0;
        $personal = (int) $s['personal_allowance'];
        if (! $fuel && ! $personal) {
            return 0;
        }
        $from = $day->startOfDay()->utc();
        $to = $day->endOfDay()->utc();
        $worked = OrderAssignment::query()->whereIn('status', [AssignmentStatus::Completed->value, AssignmentStatus::Failed->value])
            ->whereBetween('completed_at', [$from, $to])
            ->whereHas('courier', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $company->id))
            ->distinct()->pluck('courier_id');
        $n = 0;

        foreach (Courier::withoutGlobalScopes()->whereIn('id', $worked)->get() as $courier) {
            // Véhicule conduit ce jour-là
            $assignment = VehicleAssignment::where('courier_id', $courier->id)->where('starts_at', '<=', $to)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $from))->latest('starts_at')->with('vehicle')->first();
            $vehicle = $assignment?->vehicle;
            if ($vehicle === null) {
                continue;
            }
            $own = $vehicle->ownership === 'courier';
            foreach ([[EarningType::FuelAllowance, $fuel && ! $own ? $fuel : 0, 'Dotation carburant'], [EarningType::VehicleAllowance, $personal && $own ? $personal : 0, 'Indemnité véhicule personnel']] as [$type, $amount, $label]) {
                if (! $amount || CourierEarning::withoutGlobalScopes()->where('courier_id', $courier->id)->where('type', $type->value)->whereDate('period_start', $day->toDateString())->exists()) {
                    continue;
                }
                CourierEarning::create([
                    'company_id' => $company->id,
                    'courier_id' => $courier->id,
                    'type' => $type,
                    'amount' => $amount,
                    'description' => "{$label} du ".$day->format('d/m')." · {$vehicle->plate} (".Money::format($amount).')',
                    'period_start' => $day->toDateString(),
                    'period_end' => $day->toDateString(),
                ]);
                $n++;
            }
        }

        return $n;
    }

    /** Véhicules pris pour la journée et pas rendus (pas d'état des lieux du retour) : rendus le lendemain */
    private function release(Company $company, CarbonImmutable $today): int
    {
        $open = VehicleAssignment::forCompany($company->id)->whereNull('ends_at')->where('kind', 'day')
            ->where('starts_at', '<', $today->utc())->with('vehicle')->get();
        foreach ($open as $assignment) {
            if ($assignment->vehicle) {
                $this->fleet->assign(null, $assignment->vehicle, null);
            }
        }

        return $open->count();
    }
}
