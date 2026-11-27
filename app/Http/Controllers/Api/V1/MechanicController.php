<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RepairResource;
use App\Models\Repair;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Services\Vehicles\Fleet;
use App\Services\Workshop\RepairDispatcher;
use App\Services\Workshop\WorkshopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Application mécanicien : ses pannes (proposée, à traiter, en cours, terminées), en service ou non, sa position,
 * les véhicules du parc (fiche, carnet, entretiens).
 */
class MechanicController extends Controller
{
    public function __construct(
        private readonly RepairDispatcher $dispatcher,
        private readonly WorkshopSettings $settings,
        private readonly Fleet $fleet,
    ) {}

    /**
     * Pannes du mécanicien : celle qui lui est proposée (alerte), celles sans mécanicien qu'il peut prendre,
     * les siennes en cours et celles terminées ces 7 derniers jours.
     */
    public function repairs(Request $request): JsonResponse
    {
        $mechanic = $this->dispatcher->mechanicOf($request->user());
        $with = ['courier.user', 'vehicle', 'mechanic.user'];

        $offered = Repair::with($with)->where('status', Repair::OFFERED)->where('mechanic_id', $mechanic->id)->latest('id')->get();
        $waiting = Repair::with($with)->where('status', Repair::SEARCHING)->latest('id')->get();
        $current = Repair::with($with)->whereIn('status', Repair::IN_PROGRESS)->where('mechanic_id', $mechanic->id)->orderBy('accepted_at')->get();
        $done = Repair::with($with)->whereIn('status', [Repair::DONE, Repair::CANCELLED])->where('mechanic_id', $mechanic->id)
            ->where('updated_at', '>=', now()->subDays(7))->latest('updated_at')->limit(50)->get();
        $rank = ['urgent' => 0, 'blocking' => 1, 'hindrance' => 2, 'info' => 3];
        $todo = $offered->concat($waiting)->sortBy(fn (Repair $r) => [$r->status === Repair::OFFERED ? 0 : 1, $rank[$r->severity] ?? 9, $r->id])->values();

        return response()->json(['data' => [
            'mechanic' => [
                'id' => $mechanic->id,
                'is_available' => $mechanic->is_available,
                'available_since' => $mechanic->available_since,
            ],
            'offer' => $offered->first() ? RepairResource::make($offered->first()) : null,
            'todo' => RepairResource::collection($todo),
            'current' => RepairResource::collection($current),
            'done' => RepairResource::collection($done),
        ]]);
    }

    /** Listes du diagnostic et de la clôture */
    public function setup(Request $request): JsonResponse
    {
        $settings = $this->settings->for($request->user()->company);

        return response()->json(['data' => [
            'categories' => $settings['categories'],
            'decisions' => collect(Repair::DECISIONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'causes' => collect(Repair::CAUSES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'offer_minutes' => $settings['offer_minutes'],
        ]]);
    }

    /**
     * En service ou non, et position (envoyée régulièrement pendant le service). Mis en service : les pannes sans
     * mécanicien lui sont proposées ; hors service : la panne qui lui était proposée passe au suivant.
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $mechanic = $this->dispatcher->mechanicOf($request->user());

        if (isset($data['lat'], $data['lng'])) {
            $mechanic->forceFill(['current_lat' => $data['lat'], 'current_lng' => $data['lng'], 'last_location_at' => now()]);
        }
        $changed = array_key_exists('is_available', $data) && $data['is_available'] !== $mechanic->is_available;
        if ($changed) {
            $mechanic->forceFill(['is_available' => $data['is_available'], 'available_since' => $data['is_available'] ? now() : null]);
        }
        $mechanic->save();

        if ($changed && $mechanic->is_available) {
            $this->dispatcher->retrySearching($mechanic->company_id);
        } elseif ($changed) {
            Repair::where('status', Repair::OFFERED)->where('mechanic_id', $mechanic->id)->get()
                ->each(fn (Repair $r) => $this->dispatcher->decline($request->user(), $r));
        }

        return response()->json(['data' => [
            'is_available' => $mechanic->is_available,
            'available_since' => $mechanic->available_since,
        ]]);
    }

    /** Véhicules du parc (recherche par plaque, marque ou livreur) */
    public function vehicles(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $vehicles = Vehicle::query()->with('courier.user')->whereNotIn('status', ['sold'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('plate', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")->orWhere('model', 'like', "%{$search}%")
                ->orWhereHas('courier.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
            ->orderBy('plate')->limit(100)->get();

        return response()->json(['data' => $vehicles->map(fn (Vehicle $v) => [
            'id' => $v->id,
            'plate' => $v->plate,
            'name' => trim($v->brand.' '.$v->model) ?: null,
            'type' => $v->type,
            'status' => $v->status,
            'status_label' => Vehicle::STATUSES[$v->status] ?? $v->status,
            'courier' => $v->courier?->user?->name,
        ])->values()]);
    }

    /** Fiche d'un véhicule : compteur, livreur, entretiens dus, carnet */
    public function vehicle(Vehicle $vehicle): JsonResponse
    {
        $vehicle->load('courier.user');

        return response()->json(['data' => [
            'id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'name' => trim($vehicle->brand.' '.$vehicle->model) ?: null,
            'type' => $vehicle->type,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'odometer' => $vehicle->odometer,
            'status' => $vehicle->status,
            'status_label' => Vehicle::STATUSES[$vehicle->status] ?? $vehicle->status,
            'courier' => $vehicle->courier?->user ? ['name' => $vehicle->courier->user->name, 'phone' => $vehicle->courier->user->phone] : null,
            'maintenance' => $this->fleet->maintenance($vehicle),
            'remarks' => Repair::where('vehicle_id', $vehicle->id)->where('status', Repair::DONE)->whereNotNull('remark')
                ->latest('finished_at')->limit(3)->get(['id', 'remark', 'finished_at'])->values(),
            'logs' => VehicleLog::where('vehicle_id', $vehicle->id)->whereIn('kind', ['maintenance', 'repair', 'claim'])
                ->latest('done_on')->latest('id')->limit(30)->get()->map(fn (VehicleLog $l) => [
                    'id' => $l->id,
                    'kind' => $l->kind,
                    'kind_label' => VehicleLog::KINDS[$l->kind] ?? $l->kind,
                    'icon' => VehicleLog::ICONS[$l->kind] ?? 'note',
                    'label' => $l->label,
                    'done_on' => $l->done_on?->toDateString(),
                    'odometer' => $l->odometer,
                    'amount' => $l->amount,
                ])->values(),
        ]]);
    }
}
