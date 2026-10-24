<?php

namespace App\Services\Vehicles;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleCheck;
use App\Notifications\VehicleAlert;
use App\Services\Couriers\CourierTracker;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * États des lieux des véhicules par les livreurs : au départ (chaque jour ou chaque lundi, selon l'entreprise),
 * et au retour si elle le veut. Compteur (photo), essence, contrôle rapide ; un problème prévient l'équipe sans
 * empêcher de partir. Un livreur sans véhicule attitré peut prendre un véhicule libre pour la journée, rendu au
 * retour (ou le lendemain).
 */
class VehicleChecks
{
    public function __construct(
        private readonly Fleet $fleet,
        private readonly VehicleSettings $settings,
        private readonly CourierTracker $tracker,
    ) {}

    /** L'état des lieux du départ est-il à faire maintenant ? */
    public function needed(Courier $courier): bool
    {
        $s = $this->settings->for(Company::find($courier->company_id));
        if ($s['check_mode'] === 'never') {
            return false;
        }
        $vehicle = $this->fleet->currentFor($courier);
        if ($vehicle === null) {
            return false;
        }
        $since = $this->windowStart($courier, $s['check_mode']);

        return ! VehicleCheck::where('courier_id', $courier->id)->where('vehicle_id', $vehicle->id)->where('moment', 'start')
            ->where('created_at', '>=', $since)->exists();
    }

    /**
     * Ce que l'application du livreur affiche en haut de ses missions : son véhicule, l'état des lieux à faire,
     * l'entretien à prévoir, ou les véhicules libres à prendre pour la journée.
     *
     * @return array<string, mixed>|null
     */
    public function summary(Courier $courier): ?array
    {
        $s = $this->settings->for(Company::find($courier->company_id));
        $vehicle = $this->fleet->currentFor($courier);
        if ($vehicle === null) {
            $free = $s['check_mode'] === 'never' ? 0 : $this->freeVehicles($courier->company_id)->count();

            return $free ? ['vehicle' => null, 'free' => $free, 'check_needed' => false, 'blocks_service' => false] : null;
        }
        $next = collect($this->fleet->maintenance($vehicle, $s))->where('state', '!=', 'ok')->sortBy('remaining_km')->first();
        $last = VehicleCheck::where('vehicle_id', $vehicle->id)->whereNotNull('odometer')->latest('id')->first();

        return [
            'vehicle' => ['id' => $vehicle->id, 'plate' => $vehicle->plate, 'name' => trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')),
                'type' => $vehicle->type->value, 'odometer' => $vehicle->odometer, 'last_check_at' => $last?->created_at],
            'problems' => $this->fleet->problems($vehicle),
            'check_needed' => $this->needed($courier),
            'blocks_service' => $this->blocksService($courier),
            'check_end' => (bool) $s['check_end'],
            'maintenance' => $next ? ['label' => $next['label'], 'icon' => $next['icon'] ?? '🔧', 'state' => $next['state'], 'remaining_km' => $next['remaining_km'], 'remaining_days' => $next['remaining_days']] : null,
        ];
    }

    /** Doit-il le faire avant de se mettre en service ? */
    public function blocksService(Courier $courier): bool
    {
        return $this->settings->for(Company::find($courier->company_id))['check_before_service'] && $this->needed($courier);
    }

    private function windowStart(Courier $courier, string $mode): CarbonInterface
    {
        $local = now()->setTimezone(Company::find($courier->company_id)?->timezone ?: config('app.timezone'));

        return ($mode === 'weekly' ? $local->startOfWeek() : $local->startOfDay())->utc();
    }

    /**
     * Véhicules de l'entreprise libres et en état, à prendre pour la journée.
     *
     * @return Collection<int, Vehicle>
     */
    public function freeVehicles(int $companyId)
    {
        return Vehicle::forCompany($companyId)->whereNull('courier_id')->where('status', 'active')
            ->whereIn('ownership', ['company', 'rental'])->orderBy('plate')->get()
            ->filter(fn (Vehicle $v) => $this->fleet->usable($v))->values();
    }

    /**
     * @param  array<string, mixed>  $data  moment, vehicle_id (véhicule libre pris pour la journée), odometer, fuel, items, note
     * @param  list<UploadedFile>  $photos
     */
    public function record(Courier $courier, array $data, array $photos = []): VehicleCheck
    {
        $s = $this->settings->for(Company::find($courier->company_id));
        $moment = ($data['moment'] ?? 'start') === 'end' ? 'end' : 'start';
        $vehicle = $this->fleet->currentFor($courier);

        if ($vehicle === null && $moment === 'start' && ! empty($data['vehicle_id'])) {
            $vehicle = $this->freeVehicles($courier->company_id)->firstWhere('id', (int) $data['vehicle_id'])
                ?? throw new BusinessRuleException('Ce véhicule n\'est plus libre.', 'vehicle_id');
            $this->fleet->assign($courier->user, $vehicle, $courier, 'day');
        }
        if ($vehicle === null) {
            throw new BusinessRuleException('Aucun véhicule à votre nom : choisissez-en un, ou voyez avec l\'agence.', 'vehicle_id');
        }
        if (! empty($data['odometer']) && (int) $data['odometer'] < $vehicle->odometer - 5) {
            throw new BusinessRuleException("Le compteur ne peut pas reculer (dernier relevé : {$vehicle->odometer} km).", 'odometer');
        }

        $allowed = array_flip($s['check_items']);
        $items = array_intersect_key((array) ($data['items'] ?? []), $allowed);
        $items = array_map(fn ($v) => $v === 'problem' ? 'problem' : 'ok', $items);
        $problems = count(array_filter($items, fn ($v) => $v === 'problem'));

        $check = DB::transaction(function () use ($courier, $vehicle, $data, $photos, $moment, $items, $problems) {
            $check = VehicleCheck::create([
                'company_id' => $courier->company_id,
                'vehicle_id' => $vehicle->id,
                'courier_id' => $courier->id,
                'moment' => $moment,
                'odometer' => $data['odometer'] ?? null,
                'fuel' => $data['fuel'] ?? null,
                'items' => $items,
                'problems' => $problems,
                'note' => $data['note'] ?? null,
                'photos' => array_map(fn (UploadedFile $f) => $f->store("vehicles/{$vehicle->id}/checks", 'local'), $photos),
                // Retour : distance du trajet GPS depuis le départ du jour
                'gps_km' => $moment === 'end' ? $this->tracker->kilometers($courier, $this->windowStart($courier, 'daily'), now()) : null,
            ]);
            $vehicle->readOdometer($check->odometer);
            $vehicle->save();

            // Véhicule pris pour la journée : rendu au retour
            if ($moment === 'end' && VehicleAssignment::where('vehicle_id', $vehicle->id)->whereNull('ends_at')->where('kind', 'day')->exists()) {
                $this->fleet->assign(null, $vehicle, null);
            }

            return $check;
        });

        if ($problems > 0) {
            $labels = collect($items)->filter(fn ($v) => $v === 'problem')->keys()->map(fn ($k) => VehicleCatalog::CHECK_ITEMS[$k]['label'] ?? $k)->implode(', ');
            $users = User::forCompany($courier->company_id)->where('status', 'active')->role([Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value])->get();
            $courier->loadMissing('user');
            Notification::send($users, new VehicleAlert('check_problem', "🔧 {$vehicle->plate} : {$labels}",
                "Signalé par {$courier->user?->name} à l'état des lieux".($check->note ? " : « {$check->note} »" : '.'), "/admin/vehicules/{$vehicle->id}?onglet=historique"));
        }

        return $check;
    }
}
