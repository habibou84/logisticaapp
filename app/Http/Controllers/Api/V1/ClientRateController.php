<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryDelay;
use App\Enums\Permission;
use App\Enums\ShipmentType;
use App\Http\Controllers\Controller;
use App\Models\DistributionRate;
use App\Models\InteriorRate;
use App\Services\Pricing\ClientPricing;
use App\Services\Pricing\InteriorRateImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tarifs des clients sous formule : distribution à l'unité sur Abidjan (par délai et type d'envoi) et
 * villes de l'intérieur (pli, colis, kilogramme en plus, par délai), saisis ou importés.
 */
class ClientRateController extends Controller
{
    public function distribution(Request $request): JsonResponse
    {
        $this->authorize($request, read: true);
        $prices = DistributionRate::all()->keyBy(fn (DistributionRate $r) => $r->delivery_delay->value.'|'.$r->shipment_type->value);

        return response()->json(['data' => collect(DeliveryDelay::cases())->map(fn (DeliveryDelay $d) => [
            'delivery_delay' => $d->value,
            'label' => $d->label(),
            'prices' => collect(ShipmentType::cases())->mapWithKeys(fn (ShipmentType $t) => [$t->value => $prices[$d->value.'|'.$t->value]?->price ?? null]),
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

        DB::transaction(function () use ($data, $request) {
            foreach ($data['rates'] as $rate) {
                $keys = ['company_id' => $request->user()->company_id, 'delivery_delay' => $rate['delivery_delay'], 'shipment_type' => $rate['shipment_type']];
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

        return response()->json(['data' => InteriorRate::query()->orderBy('city')->get()
            ->sortBy(fn (InteriorRate $r) => ClientPricing::cityKey($r->city).'|'.$r->delivery_delay->hours())
            ->values()->map(fn (InteriorRate $r) => $this->interiorRow($r))]);
    }

    public function storeInterior(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate($this->interiorRules());
        $rate = InteriorRate::query()->get()->first(fn (InteriorRate $r) => ClientPricing::cityKey($r->city) === ClientPricing::cityKey($data['city'])
            && $r->delivery_delay->value === $data['delivery_delay']) ?? new InteriorRate(['company_id' => $request->user()->company_id]);
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

        return response()->json(['data' => $importer->import($request->user()->company, $file->getRealPath(), $extension, $request->boolean('dry_run'))]);
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

    private function authorize(Request $request, bool $read = false): void
    {
        $user = $request->user();
        abort_unless($user->canAny([Permission::SettingsManage->value, Permission::RatesManage->value])
            || ($read && $user->canAny([Permission::OrdersCreate->value, Permission::FinanceView->value, Permission::InvoicesView->value])), 403);
    }
}
