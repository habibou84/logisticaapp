<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FeePayer;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Shop\ShopCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Réglages de la page de commande, par le gérant de la boutique.
 */
class MerchantShopController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->data($this->merchant($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        if (is_string($request->input('shop_slug'))) {
            $request->merge(['shop_slug' => Str::slug($request->input('shop_slug'))]);
        }

        $data = $request->validate([
            'shop_enabled' => ['sometimes', 'boolean'],
            'shop_slug' => ['sometimes', 'nullable', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('merchants', 'shop_slug')->ignore($merchant->id)],
            'shop_intro' => ['sometimes', 'nullable', 'string', 'max:300'],
            'shop_fee_payer' => ['sometimes', Rule::enum(FeePayer::class)],
        ], ['shop_slug.unique' => 'Ce lien est déjà pris : choisissez-en un autre.'], ['shop_slug' => 'lien de la boutique', 'shop_intro' => 'présentation']);

        $merchant->fill($data);
        // Première ouverture sans lien choisi : lien tiré du nom de la boutique
        if ($merchant->shop_enabled && blank($merchant->shop_slug)) {
            $merchant->shop_slug = self::freeSlug($merchant);
        }
        $merchant->save();

        return response()->json(['data' => $this->data($merchant)]);
    }

    public static function freeSlug(Merchant $merchant): string
    {
        $base = Str::limit(Str::slug($merchant->business_name), 50, '') ?: 'boutique';
        $base = strlen($base) < 3 ? "boutique-{$base}" : $base;
        $slug = $base;

        for ($i = 2; Merchant::withTrashed()->where('shop_slug', $slug)->whereKeyNot($merchant->id)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Merchant $merchant): array
    {
        return [
            'shop_enabled' => $merchant->shop_enabled,
            'shop_slug' => $merchant->shop_slug,
            'shop_intro' => $merchant->shop_intro,
            'shop_fee_payer' => ShopCheckout::feePayer($merchant)->value,
            'suggested_slug' => $merchant->shop_slug ?? self::freeSlug($merchant),
            'url' => $merchant->shop_slug ? $merchant->company->url("/b/{$merchant->shop_slug}") : null,
        ];
    }

    private function merchant(Request $request): Merchant
    {
        $user = $request->user();
        abort_unless($user->merchant_id !== null && $user->can(Permission::IntegrationsManage->value), 403);

        return Merchant::findOrFail($user->merchant_id);
    }
}
