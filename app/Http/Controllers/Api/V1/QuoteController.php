<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryDelay;
use App\Enums\ShipmentType;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Zone;
use App\Services\Orders\ZoneResolver;
use App\Services\Pricing\ClientPricing;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    /**
     * Devis instantané (affiché pendant la saisie d'une course).
     */
    public function __invoke(Request $request, PricingService $pricing, ClientPricing $clientPricing): JsonResponse
    {
        $user = $request->user();

        // Zones données par leur nom (« Cocody ») : même règle que la création de course
        if ($errors = app(ZoneResolver::class)->resolveInto($request, $user->company_id)) {
            throw ValidationException::withMessages($errors);
        }

        $zone = Rule::exists('zones', 'id')->where('company_id', $user->company_id);

        $data = $request->validate([
            'merchant_id' => [Rule::requiredIf($user->merchant_id === null), 'integer', Rule::exists('merchants', 'id')->where('company_id', $user->company_id)],
            'pickup_zone_id' => ['nullable', 'integer', $zone],
            'delivery_zone_id' => ['required_without:delivery_zone', 'nullable', 'integer', $zone],
            'is_express' => ['sometimes', 'boolean'],
            'is_fragile' => ['sometimes', 'boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'shipment_type' => ['nullable', Rule::enum(ShipmentType::class)],
            'delivery_delay' => ['nullable', Rule::enum(DeliveryDelay::class)],
        ]);

        $merchant = Merchant::findOrFail($user->merchant_id ?? $data['merchant_id']);
        $pickupZone = Zone::findOrFail($data['pickup_zone_id'] ?? $merchant->pickup_zone_id);
        $deliveryZone = Zone::findOrFail($data['delivery_zone_id']);

        // Client sous formule : rien à payer sur la course, elle passe sur la facture du mois
        if ($billed = $clientPricing->price($merchant, $pickupZone, $deliveryZone, $data)) {
            return response()->json(['data' => [
                'base_price' => 0,
                'surcharges' => [],
                'surcharges_total' => 0,
                'total' => 0,
                'billing' => $billed['pricing_details']['billing'],
                'shipment_type' => $billed['shipment_type']->value,
                'delivery_delay' => $billed['delivery_delay']->value,
                'is_shipping' => $deliveryZone->is_shipping,
                'shipping_fee_estimate' => $deliveryZone->is_shipping ? $deliveryZone->shipping_fee_estimate : null,
            ]]);
        }

        return response()->json(['data' => [
            ...$pricing->quote($merchant, $pickupZone, $deliveryZone, $data)->toArray(),
            // Expédition : frais du transporteur en plus, facturés au réel
            'is_shipping' => $deliveryZone->is_shipping,
            'shipping_fee_estimate' => $deliveryZone->is_shipping ? $deliveryZone->shipping_fee_estimate : null,
        ]]);
    }
}
