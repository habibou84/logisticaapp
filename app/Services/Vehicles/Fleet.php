<?php

namespace App\Services\Vehicles;

use App\Enums\VehicleType;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Models\VehicleLease;
use App\Models\VehicleLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parc de véhicules : création, remise à un livreur (attitré ou pour la journée), état, papiers et entretien
 * (échéances), véhicule utilisable ou non, alertes et coûts. Un livreur n'a qu'un véhicule à la fois ; le type et
 * la plaque de sa fiche suivent son véhicule (l'assignation automatique s'en sert déjà).
 */
class Fleet
{
    /** @var array<int, Vehicle|false> véhicule actuel par livreur (le temps d'une requête) */
    private array $current = [];

    public function __construct(private readonly VehicleSettings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Vehicle
    {
        return DB::transaction(function () use ($actor, $data) {
            $courierId = $data['courier_id'] ?? null;
            unset($data['courier_id']);
            $vehicle = Vehicle::create([
                ...$data,
                'company_id' => $actor->company_id,
                'status' => $data['status'] ?? 'active',
                'odometer' => (int) ($data['odometer'] ?? 0),
                'odometer_at' => now(),
            ]);
            $vehicle->forceFill(['maintenance_plan' => $this->defaultPlan($vehicle)])->save();
            if ($courierId) {
                $this->assign($actor, $vehicle, Courier::findOrFail($courierId));
            }

            return $vehicle->fresh();
        });
    }

    /**
     * Plan d'entretien proposé pour ce type de véhicule (Réglages › Véhicules), compté à partir d'aujourd'hui.
     *
     * @return list<array<string, mixed>>
     */
    public function defaultPlan(Vehicle $vehicle): array
    {
        $plans = $this->settings->for($vehicle->company ?? Company::find($vehicle->company_id))['default_plans'];

        return array_values(array_map(fn ($item) => [...$item, 'last_km' => $vehicle->odometer, 'last_on' => now()->toDateString()],
            $plans[$vehicle->type->value] ?? []));
    }

    /**
     * Remet le véhicule à un livreur (ou le reprend, sans livreur). Le livreur rend le véhicule qu'il avait.
     */
    public function assign(?User $actor, Vehicle $vehicle, ?Courier $courier, string $kind = 'assigned'): Vehicle
    {
        if ($courier && in_array($vehicle->status, ['sold', 'out'], true)) {
            throw new BusinessRuleException('Ce véhicule est hors service ou vendu.', 'vehicle_id');
        }

        return DB::transaction(function () use ($actor, $vehicle, $courier, $kind) {
            $this->close($vehicle);
            if ($courier) {
                Vehicle::where('courier_id', $courier->id)->whereKeyNot($vehicle->id)->get()->each(function (Vehicle $other) {
                    $this->close($other);
                    $other->forceFill(['courier_id' => null])->save();
                });
                VehicleAssignment::create([
                    'company_id' => $vehicle->company_id,
                    'vehicle_id' => $vehicle->id,
                    'courier_id' => $courier->id,
                    'kind' => $kind,
                    'starts_at' => now(),
                    'odometer_start' => $vehicle->odometer,
                    'created_by' => $actor?->id,
                ]);
                // La fiche du livreur suit son véhicule (type, plaque)
                $courier->forceFill(['vehicle_type' => $vehicle->type, 'vehicle_plate' => $vehicle->plate])->save();
            }
            $vehicle->forceFill(['courier_id' => $courier?->id])->save();
            $this->current = [];

            return $vehicle;
        });
    }

    /** Fin de la remise en cours du véhicule */
    private function close(Vehicle $vehicle): void
    {
        VehicleAssignment::where('vehicle_id', $vehicle->id)->whereNull('ends_at')
            ->update(['ends_at' => now(), 'odometer_end' => $vehicle->odometer]);
    }

    public function setStatus(Vehicle $vehicle, string $status): Vehicle
    {
        if (! array_key_exists($status, Vehicle::STATUSES)) {
            throw new BusinessRuleException('État inconnu.', 'status');
        }
        $vehicle->forceFill(['status' => $status, ...($status === 'active' ? ['field_event_id' => null] : [])])->save();
        if (in_array($status, ['sold', 'out'], true) && $vehicle->courier_id) {
            $this->assign(null, $vehicle, null);
        }
        $this->current = [];

        return $vehicle;
    }

    /** Véhicule que le livreur a en ce moment */
    public function currentFor(Courier $courier): ?Vehicle
    {
        $this->current[$courier->id] ??= Vehicle::query()->withoutGlobalScopes()->where('courier_id', $courier->id)->first() ?? false;

        return $this->current[$courier->id] ?: null;
    }

    /** Livreur qui avait le véhicule à ce moment (amendes, dégâts) */
    public function holderAt(Vehicle $vehicle, CarbonInterface $at): ?int
    {
        return VehicleAssignment::where('vehicle_id', $vehicle->id)->where('starts_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->latest('starts_at')->value('courier_id');
    }

    /**
     * Ce qui empêche d'utiliser le véhicule : état, papier obligatoire dépassé (si l'entreprise le veut).
     *
     * @return list<string>
     */
    public function problems(Vehicle $vehicle): array
    {
        $problems = [];
        if ($vehicle->status !== 'active') {
            $problems[] = Vehicle::STATUSES[$vehicle->status] ?? $vehicle->status;
        }
        $s = $this->settings->for(Company::find($vehicle->company_id));
        if ($s['block_expired'] && $s['blocking_documents'] !== []) {
            $expired = $this->latestDocuments($vehicle)->filter(fn (VehicleDocument $d) => in_array($d->kind, $s['blocking_documents'], true)
                && $d->ends_on !== null && $d->ends_on->lt(today()));
            foreach ($expired as $doc) {
                $problems[] = (VehicleCatalog::DOCUMENTS[$doc->kind]['label'] ?? $doc->kind).' dépassée';
            }
        }

        return $problems;
    }

    public function usable(Vehicle $vehicle): bool
    {
        return $this->problems($vehicle) === [];
    }

    /**
     * Le livreur peut-il recevoir des missions côté véhicule ? Seulement si l'entreprise le demande : il lui faut
     * un véhicule en état (sauf à pied).
     */
    public function canWork(Courier $courier): bool
    {
        $company = $courier->relationLoaded('company') ? $courier->company : Company::find($courier->company_id);
        if (! $this->settings->for($company)['no_vehicle_no_missions'] || $courier->vehicle_type === VehicleType::Pieton) {
            return true;
        }
        $vehicle = $this->currentFor($courier);

        return $vehicle !== null && $this->usable($vehicle);
    }

    /**
     * Colis en cours au plus pour la livraison automatique : la limite du livreur, et celle de son véhicule si
     * l'entreprise utilise la capacité des véhicules.
     */
    public function maxParcels(Courier $courier): ?int
    {
        $company = $courier->relationLoaded('company') ? $courier->company : Company::find($courier->company_id);
        $vehicle = $this->settings->for($company)['capacity_assignment'] ? $this->currentFor($courier)?->capacity_parcels : null;
        $limits = array_filter([$courier->max_parcels, $vehicle], fn ($v) => $v !== null);

        return $limits ? min($limits) : null;
    }

    /**
     * Dernier papier de chaque sorte (le plus récent remplace les anciens).
     *
     * @return Collection<int, VehicleDocument>
     */
    public function latestDocuments(Vehicle $vehicle)
    {
        return VehicleDocument::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->orderByDesc('ends_on')->orderByDesc('id')->get()
            ->unique(fn (VehicleDocument $d) => $d->kind === 'other' ? 'other-'.$d->id : $d->kind)->values();
    }

    /**
     * État d'un papier : ok, soon (dans le délai de rappel), expired, permanent.
     *
     * @return array{state: string, days: ?int}
     */
    public function documentState(VehicleDocument $doc, ?array $settings = null): array
    {
        if ($doc->ends_on === null) {
            return ['state' => 'permanent', 'days' => null];
        }
        $settings ??= $this->settings->for(Company::find($doc->company_id));
        $days = (int) today()->diffInDays($doc->ends_on, false);

        return ['state' => $days < 0 ? 'expired' : ($days <= max($settings['reminder_days'] ?: [0]) ? 'soon' : 'ok'), 'days' => $days];
    }

    /**
     * Plan d'entretien avec ce qui reste avant chaque échéance (km et jours) : ok, soon, due.
     *
     * @return list<array<string, mixed>>
     */
    public function maintenance(Vehicle $vehicle, ?array $settings = null): array
    {
        $settings ??= $this->settings->for(Company::find($vehicle->company_id));

        return array_values(array_map(function (array $item) use ($vehicle, $settings) {
            $remainingKm = $item['every_km'] ? (int) ($item['last_km'] ?? 0) + (int) $item['every_km'] - $vehicle->odometer : null;
            $dueOn = $item['every_months'] && ! empty($item['last_on']) ? CarbonImmutable::parse($item['last_on'])->addMonths((int) $item['every_months']) : null;
            $remainingDays = $dueOn ? (int) today()->diffInDays($dueOn, false) : null;
            $due = ($remainingKm !== null && $remainingKm <= 0) || ($remainingDays !== null && $remainingDays <= 0);
            $soon = ($remainingKm !== null && $remainingKm <= $settings['maintenance_reminder_km'])
                || ($remainingDays !== null && $remainingDays <= $settings['maintenance_reminder_days']);
            // Avancement vers l'échéance (0 à 100), la plus proche des deux
            $progress = max(
                $item['every_km'] ? 100 - 100 * max(0, $remainingKm) / max(1, $item['every_km']) : 0,
                $dueOn ? 100 - 100 * max(0, $remainingDays) / max(1, (int) CarbonImmutable::parse($item['last_on'])->diffInDays($dueOn)) : 0,
            );

            return [...$item, 'remaining_km' => $remainingKm, 'due_on' => $dueOn?->toDateString(), 'remaining_days' => $remainingDays,
                'state' => $due ? 'due' : ($soon ? 'soon' : 'ok'), 'progress' => (int) min(100, round($progress))];
        }, $vehicle->maintenance_plan ?? []));
    }

    /**
     * Ce qu'il y a à faire sur le parc : papiers dépassés ou proches, entretien, véhicules immobilisés, versements en retard.
     *
     * @return list<array<string, mixed>>
     */
    public function alerts(int $companyId): array
    {
        $company = Company::find($companyId);
        $s = $this->settings->for($company);
        $alerts = [];
        $vehicles = Vehicle::forCompany($companyId)->whereNotIn('status', ['sold'])->get();

        foreach ($vehicles as $v) {
            foreach ($this->latestDocuments($v) as $doc) {
                $state = $this->documentState($doc, $s);
                if (in_array($state['state'], ['expired', 'soon'], true)) {
                    $alerts[] = ['kind' => 'document', 'icon' => VehicleCatalog::DOCUMENTS[$doc->kind]['icon'] ?? '📄', 'state' => $state['state'],
                        'vehicle_id' => $v->id, 'title' => $v->plate, 'label' => VehicleCatalog::DOCUMENTS[$doc->kind]['label'] ?? $doc->label,
                        'days' => $state['days'], 'date' => $doc->ends_on?->toDateString(), 'document_id' => $doc->id];
                }
            }
            foreach ($this->maintenance($v, $s) as $item) {
                if ($item['state'] !== 'ok') {
                    $alerts[] = ['kind' => 'maintenance', 'icon' => $item['icon'] ?? '🔧', 'state' => $item['state'] === 'due' ? 'expired' : 'soon',
                        'vehicle_id' => $v->id, 'title' => $v->plate, 'label' => $item['label'],
                        'km' => $item['remaining_km'], 'days' => $item['remaining_days'], 'date' => $item['due_on']];
                }
            }
            if (in_array($v->status, ['broken', 'maintenance', 'immobilized'], true)) {
                $alerts[] = ['kind' => 'status', 'icon' => '⛔', 'state' => 'expired', 'vehicle_id' => $v->id, 'title' => $v->plate,
                    'label' => Vehicle::STATUSES[$v->status], 'date' => $v->updated_at?->toDateString()];
            }
        }

        if ($s['track_license']) {
            $licenses = VehicleDocument::forCompany($companyId)->where('kind', 'license')->whereNotNull('courier_id')->with('courier.user')
                ->orderByDesc('ends_on')->get()->unique('courier_id');
            foreach ($licenses as $doc) {
                $state = $this->documentState($doc, $s);
                if (in_array($state['state'], ['expired', 'soon'], true)) {
                    $alerts[] = ['kind' => 'license', 'icon' => '🪪', 'state' => $state['state'], 'courier_id' => $doc->courier_id,
                        'title' => $doc->courier?->user?->name, 'label' => 'Permis de conduire', 'days' => $state['days'], 'date' => $doc->ends_on?->toDateString()];
                }
            }
        }

        if ($s['leases']) {
            $leases = app(VehicleLeases::class);
            foreach (VehicleLease::forCompany($companyId)->where('status', 'active')->with(['vehicle', 'courier.user'])->get() as $lease) {
                $b = $leases->balance($lease);
                if ($b['late'] > 0) {
                    $alerts[] = ['kind' => 'lease', 'icon' => '💸', 'state' => 'expired', 'vehicle_id' => $lease->vehicle_id,
                        'title' => $lease->vehicle?->plate, 'label' => ($lease->kind === 'purchase' ? 'Location-vente' : 'Location').' en retard', 'courier' => $lease->courier?->user?->name,
                        'amount' => $b['late'], 'lease_id' => $lease->id];
                }
            }
        }

        usort($alerts, fn ($a, $b) => [$a['state'] !== 'expired', $a['days'] ?? -999] <=> [$b['state'] !== 'expired', $b['days'] ?? -999]);

        return $alerts;
    }

    /**
     * Coûts d'un véhicule sur une période (sinon depuis toujours) : carburant, entretien, réparations, sinistres,
     * amendes, papiers, autres.
     *
     * @return array<string, int>
     */
    public function costs(Vehicle $vehicle, ?string $from = null, ?string $to = null): array
    {
        $logs = VehicleLog::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)
            ->when($from, fn ($q) => $q->whereDate('done_on', '>=', $from))->when($to, fn ($q) => $q->whereDate('done_on', '<=', $to))
            ->selectRaw('kind, SUM(amount) as total, SUM(COALESCE(liters, 0)) as liters')->groupBy('kind')->get()->keyBy('kind');
        $documents = (int) VehicleDocument::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)
            ->when($from, fn ($q) => $q->whereDate('starts_on', '>=', $from))->when($to, fn ($q) => $q->whereDate('starts_on', '<=', $to))->sum('cost');
        $costs = ['documents' => $documents];
        foreach (array_keys(VehicleLog::KINDS) as $kind) {
            $costs[$kind] = (int) ($logs[$kind]->total ?? 0);
        }
        $costs['total'] = array_sum($costs);
        $costs['liters'] = (int) round((float) ($logs['fuel']->liters ?? 0));

        return $costs;
    }
}
