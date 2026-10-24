<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleLease;
use App\Models\VehicleLog;
use App\Services\Vehicles\Fleet;
use App\Services\Vehicles\VehicleCatalog;
use App\Services\Vehicles\VehicleChecks;
use App\Services\Vehicles\VehicleLeases;
use App\Services\Vehicles\VehicleLogs;
use App\Services\Vehicles\VehicleSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Véhicule côté livreur : son véhicule (papiers, entretien, location-vente, carburant du mois), l'état des
 * lieux (départ, retour, véhicule libre pris pour la journée) et ses pleins.
 */
class CourierVehicleController extends Controller
{
    public function __construct(
        private readonly Fleet $fleet,
        private readonly VehicleChecks $checks,
        private readonly VehicleSettings $settings,
    ) {}

    private function courier(Request $request): Courier
    {
        return $request->user()->courier ?? abort(403);
    }

    public function show(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $s = $this->settings->for($courier->company);
        $vehicle = $this->fleet->currentFor($courier);
        $docs = $vehicle ? $this->fleet->latestDocuments($vehicle) : collect();
        if ($s['track_license']) {
            $license = VehicleDocument::where('courier_id', $courier->id)->where('kind', 'license')->orderByDesc('ends_on')->first();
            if ($license) {
                $docs->push($license);
            }
        }
        $lease = $vehicle ? VehicleLease::where('vehicle_id', $vehicle->id)->where('courier_id', $courier->id)->where('status', 'active')->first() : null;
        $fuel = $vehicle ? VehicleLog::where('vehicle_id', $vehicle->id)->where('kind', 'fuel')->where('done_on', '>=', now()->startOfMonth()->toDateString()) : null;

        return response()->json(['data' => [
            'summary' => $this->checks->summary($courier),
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id, 'plate' => $vehicle->plate, 'type' => $vehicle->type->value, 'brand' => $vehicle->brand, 'model' => $vehicle->model,
                'odometer' => $vehicle->odometer, 'ownership' => $vehicle->ownership, 'ownership_label' => Vehicle::OWNERSHIPS[$vehicle->ownership] ?? '',
                'status' => $vehicle->status, 'status_label' => Vehicle::STATUSES[$vehicle->status] ?? '',
            ] : null,
            'documents' => $docs->map(fn (VehicleDocument $d) => [
                'id' => $d->id, 'kind' => $d->kind, 'label' => VehicleCatalog::DOCUMENTS[$d->kind]['label'] ?? $d->label, 'icon' => VehicleCatalog::DOCUMENTS[$d->kind]['icon'] ?? '📄',
                'ends_on' => $d->ends_on?->toDateString(), 'has_file' => $d->file_path !== null, ...$this->fleet->documentState($d, $s),
            ])->values(),
            'maintenance' => $vehicle ? $this->fleet->maintenance($vehicle, $s) : [],
            'lease' => $lease ? [
                'kind' => $lease->kind, 'amount' => $lease->amount, 'period' => $lease->period, 'total_amount' => $lease->total_amount, 'payment' => $lease->payment,
                ...app(VehicleLeases::class)->balance($lease),
            ] : null,
            'fuel_month' => $fuel ? ['count' => (clone $fuel)->count(), 'amount' => (int) (clone $fuel)->sum('amount'), 'liters' => round((float) (clone $fuel)->sum('liters'), 1)] : null,
            'free_vehicles' => $vehicle ? [] : $this->checks->freeVehicles($courier->company_id)->map(fn (Vehicle $v) => ['id' => $v->id, 'plate' => $v->plate, 'type' => $v->type->value,
                'name' => trim(($v->brand ?? '').' '.($v->model ?? '')), 'odometer' => $v->odometer])->values(),
            'settings' => [
                'check_items' => collect($s['check_items'])->map(fn ($k) => ['value' => $k, ...VehicleCatalog::CHECK_ITEMS[$k]])->values(),
                'fuel_levels' => collect(VehicleCatalog::FUEL_LEVELS)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
                'check_odometer_photo' => $s['check_odometer_photo'],
                'fuel_mode' => $s['fuel_mode'],
                'fuel_allowance' => $s['fuel_allowance'],
                'fuel_allowance_excess_refund' => $s['fuel_allowance_excess_refund'],
                'fuel_receipt_required' => $s['fuel_receipt_required'],
            ],
        ]]);
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'moment' => ['required', Rule::in(['start', 'end'])],
            'vehicle_id' => ['nullable', 'integer'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'fuel' => ['nullable', Rule::in(array_keys(VehicleCatalog::FUEL_LEVELS))],
            'items' => ['nullable', 'array'],
            'items.*' => [Rule::in(['ok', 'problem'])],
            'note' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'max:8192'],
        ], [], ['odometer' => 'compteur']);
        $this->checks->record($this->courier($request), $data, $request->file('photos', []));

        return $this->show($request);
    }

    public function fuel(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'liters' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['image', 'max:8192'],
        ], [], ['amount' => 'montant']);
        $vehicle = $this->fleet->currentFor($courier) ?? abort(422, 'Aucun véhicule à votre nom.');
        app(VehicleLogs::class)->add($request->user(), $vehicle, [
            'kind' => 'fuel', 'done_on' => today()->toDateString(), 'courier_id' => $courier->id, 'paid_by' => 'courier',
            'amount' => $data['amount'], 'liters' => $data['liters'] ?? null, 'odometer' => $data['odometer'] ?? null, 'label' => 'Plein',
        ], $request->file('photos', []));

        return $this->show($request);
    }

    public function document(Request $request, VehicleDocument $document): StreamedResponse
    {
        $courier = $this->courier($request);
        $mine = $document->courier_id === $courier->id || ($document->vehicle_id && $document->vehicle_id === $this->fleet->currentFor($courier)?->id);
        abort_unless($mine && $document->file_path, 404);

        return Storage::disk('local')->response($document->file_path);
    }
}
