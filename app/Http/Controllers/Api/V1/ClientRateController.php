<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryDelay;
use App\Enums\Permission;
use App\Enums\ShipmentType;
use App\Http\Controllers\Controller;
use App\Models\DistributionRate;
use App\Models\InteriorRate;
use App\Models\Merchant;
use App\Services\Pricing\ClientPricing;
use App\Services\Pricing\InteriorRateImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tarifs des clients sous formule : distribution à l'unité sur Abidjan (par délai et type d'envoi) et
 * villes de l'intérieur (pli, colis, kilogramme en plus, par délai), saisis ou importés.
 */
class ClientRateController extends Controller
{
    /**
     * Clients qui ont des prix négociés (grille propre), pour choisir la grille à afficher.
     */
    public function clients(Request $request): JsonResponse
    {
        $this->authorize($request, read: true);
        $counts = fn ($model) => $model::query()->whereNotNull('merchant_id')->selectRaw('merchant_id, count(*) as total')->groupBy('merchant_id')->pluck('total', 'merchant_id');
        $interior = $counts(InteriorRate::class);
        $unit = $counts(DistributionRate::class);
        $ids = $interior->keys()->merge($unit->keys())->unique();

        return response()->json(['data' => Merchant::query()->whereIn('id', $ids)->orderBy('business_name')->get(['id', 'business_name'])
            ->map(fn (Merchant $m) => [
                'id' => $m->id,
                'business_name' => $m->business_name,
                'interior_rates' => (int) ($interior[$m->id] ?? 0),
                'unit_rates' => (int) ($unit[$m->id] ?? 0),
            ])->values()]);
    }

    public function distribution(Request $request): JsonResponse
    {
        $this->authorize($request, read: true);
        $merchantId = $this->merchantId($request);
        $key = fn (DistributionRate $r) => $r->delivery_delay->value.'|'.$r->shipment_type->value;
        $prices = DistributionRate::query()->grid($merchantId)->get()->keyBy($key);
        // Prix de la grille commune, rappelés à côté des prix négociés
        $common = $merchantId ? DistributionRate::query()->grid(null)->get()->keyBy($key) : collect();

        return response()->json(['data' => collect(DeliveryDelay::cases())->map(fn (DeliveryDelay $d) => [
            'delivery_delay' => $d->value,
            'label' => $d->label(),
            'prices' => collect(ShipmentType::cases())->mapWithKeys(fn (ShipmentType $t) => [$t->value => $prices[$d->value.'|'.$t->value]?->price ?? null]),
            'common' => collect(ShipmentType::cases())->mapWithKeys(fn (ShipmentType $t) => [$t->value => $common[$d->value.'|'.$t->value]?->price ?? null]),
        ])->values()]);
    }

    public function updateDistribution(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'rates' => ['required', 'array', 'max:20'],
            'rates.*.delivery_delay' => ['required', Rule::enum(DeliveryDelay::class)],
            'rates.*.shipment_type' => ['required', Rule::enum(ShipmentType::class)],
            'rates.*.price' => ['nullable', 'integer', 'min:0'],
        ]);

        $merchantId = $this->merchantId($request);
        DB::transaction(function () use ($data, $request, $merchantId) {
            foreach ($data['rates'] as $rate) {
                $keys = ['company_id' => $request->user()->company_id, 'merchant_id' => $merchantId, 'delivery_delay' => $rate['delivery_delay'], 'shipment_type' => $rate['shipment_type']];
                $rate['price'] === null
                    ? DistributionRate::where($keys)->delete()
                    : DistributionRate::updateOrCreate($keys, ['price' => $rate['price']]);
            }
        });

        return $this->distribution($request);
    }

    public function interior(Request $request): JsonResponse
    {
        $this->authorize($request, read: true);

        return response()->json(['data' => InteriorRate::query()->grid($this->merchantId($request))->orderBy('city')->get()
            ->sortBy(fn (InteriorRate $r) => ClientPricing::cityKey($r->city).'|'.$r->delivery_delay->hours())
            ->values()->map(fn (InteriorRate $r) => $this->interiorRow($r))]);
    }

    public function storeInterior(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate($this->interiorRules());
        // « bouaké », « BOUAKÉ » : écrit comme à l'import
        $data['city'] = Str::title(mb_strtolower(trim($data['city'])));
        $merchantId = $this->merchantId($request);
        $rate = InteriorRate::query()->grid($merchantId)->get()->first(fn (InteriorRate $r) => ClientPricing::cityKey($r->city) === ClientPricing::cityKey($data['city'])
            && $r->delivery_delay->value === $data['delivery_delay']) ?? new InteriorRate(['company_id' => $request->user()->company_id, 'merchant_id' => $merchantId]);
        $rate->fill($data)->save();
        InteriorRateImporter::ensureZone($request->user()->company_id, $rate->city);

        return response()->json(['data' => $this->interiorRow($rate)], 201);
    }

    public function updateInterior(Request $request, InteriorRate $interiorRate): JsonResponse
    {
        $this->authorize($request);
        $interiorRate->update($request->validate(collect($this->interiorRules())->only(['pli_price', 'colis_price', 'extra_kg_price'])->all()));

        return response()->json(['data' => $this->interiorRow($interiorRate)]);
    }

    public function destroyInterior(Request $request, InteriorRate $interiorRate): JsonResponse
    {
        $this->authorize($request);
        $interiorRate->delete();

        return response()->json(null, 204);
    }

    public function importInterior(Request $request, InteriorRateImporter $importer): JsonResponse
    {
        $this->authorize($request);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'], 'dry_run' => ['sometimes', 'boolean']], [], ['file' => 'fichier']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        return response()->json(['data' => $importer->import($request->user()->company, $file->getRealPath(), $extension, $request->boolean('dry_run'), $this->merchantId($request))]);
    }

    public function interiorTemplate(): Response
    {
        return response(InteriorRateImporter::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-tarifs-interieur.csv"',
        ]);
    }

    /** @return array<string, mixed> */
    private function interiorRow(InteriorRate $rate): array
    {
        return [
            'id' => $rate->id,
            'city' => $rate->city,
            'delivery_delay' => $rate->delivery_delay->value,
            'pli_price' => $rate->pli_price,
            'colis_price' => $rate->colis_price,
            'extra_kg_price' => $rate->extra_kg_price,
        ];
    }

    /** @return array<string, mixed> */
    private function interiorRules(): array
    {
        return [
            'city' => ['required', 'string', 'max:100', Rule::notIn(['Abidjan', 'abidjan', 'ABIDJAN'])],
            'delivery_delay' => ['required', Rule::enum(DeliveryDelay::class)],
            'pli_price' => ['required', 'integer', 'min:0'],
            'colis_price' => ['required', 'integer', 'min:0'],
            'extra_kg_price' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Grille demandée : celle d'un client (prix négociés) ou la grille commune (vide).
     */
    private function merchantId(Request $request): ?int
    {
        $request->validate(['merchant_id' => ['nullable', 'integer', Rule::exists('merchants', 'id')->where('company_id', $request->user()->company_id)]], [], ['merchant_id' => 'client']);

        return $request->filled('merchant_id') ? $request->integer('merchant_id') : null;
    }

    private function authorize(Request $request, bool $read = false): void
    {
        $user = $request->user();
        // Grilles de l'entreprise et prix négociés : jamais pour un compte client
        abort_if($user->merchant_id !== null, 403);
        abort_unless($user->canAny([Permission::SettingsManage->value, Permission::RatesManage->value])
            || ($read && $user->canAny([Permission::OrdersCreate->value, Permission::FinanceView->value, Permission::InvoicesView->value])), 403);
    }
}
