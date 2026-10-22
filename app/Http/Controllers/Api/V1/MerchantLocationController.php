<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Merchants\GeoLink;
use App\Services\Merchants\MerchantLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Position de ramassage d'un marchand : enregistrée par le marchand sur place (GPS de
 * son téléphone, repère ajusté sur la carte) ou par l'agence (carte, lien Google Maps).
 */
class MerchantLocationController extends Controller
{
    public function __construct(private MerchantLocation $locations) {}

    /**
     * Le marchand connecté : sa position et son adresse de ramassage.
     */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => self::data($this->ownMerchant($request))]);
    }

    public function updateMine(Request $request): JsonResponse
    {
        $merchant = $this->ownMerchant($request);
        $data = $request->validate([
            ...self::coordinates(),
            'pickup_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], self::attributes());

        $merchant->fill(collect($data)->only(['pickup_address', 'pickup_landmark'])->all())->save();
        $this->locations->set($merchant, $data['lat'], $data['lng'], Merchant::LOCATION_MERCHANT, $data['accuracy'] ?? null);

        return response()->json(['data' => self::data($merchant)]);
    }

    /**
     * L'agence (gestion des marchands) place le repère, ou retire la position (lat null).
     */
    public function update(Request $request, Merchant $merchant): JsonResponse
    {
        Gate::authorize('update', $merchant);
        $data = $request->validate([
            'lat' => ['present', 'nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['present', 'nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ], [], self::attributes());

        if ($data['lat'] === null) {
            $merchant->forceFill(['pickup_lat' => null, 'pickup_lng' => null, 'pickup_location_source' => null, 'pickup_located_at' => null, 'pickup_location_accuracy' => null])->save();
        } else {
            $this->locations->set($merchant, $data['lat'], $data['lng'], Merchant::LOCATION_STAFF);
        }

        return response()->json(['data' => self::data($merchant)]);
    }

    /**
     * Coordonnées d'un lien Google Maps (même court) ou de coordonnées écrites.
     */
    public function resolveLink(Request $request, GeoLink $links): JsonResponse
    {
        $data = $request->validate(['link' => ['required', 'string', 'max:2000']], [], ['link' => 'lien']);

        $found = $links->resolve($data['link']);
        if ($found === null) {
            throw ValidationException::withMessages(['link' => 'Lien non reconnu : dans Google Maps, touchez le lieu, puis « Partager » et « Copier le lien ».']);
        }

        return response()->json(['data' => $found]);
    }

    private function ownMerchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;
        abort_if($merchant === null, 403, 'Réservé aux e-commerçants.');

        return $merchant;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function coordinates(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return ['lat' => 'latitude', 'lng' => 'longitude', 'accuracy' => 'précision', 'pickup_address' => 'adresse', 'pickup_landmark' => 'repère'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(Merchant $merchant): array
    {
        return [
            'pickup_lat' => $merchant->pickup_lat,
            'pickup_lng' => $merchant->pickup_lng,
            'pickup_location_source' => $merchant->pickup_location_source,
            'pickup_located_at' => $merchant->pickup_located_at,
            'pickup_location_accuracy' => $merchant->pickup_location_accuracy,
            'pickup_address' => $merchant->pickup_address,
            'pickup_landmark' => $merchant->pickup_landmark,
            'pickup_zone_name' => $merchant->pickupZone?->name,
        ];
    }
}
