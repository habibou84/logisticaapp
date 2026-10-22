<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Zone;
use App\Rules\PhoneNumber;
use App\Services\Shop\ShopCheckout;
use App\Support\Branding;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Page de commande publique d'un marchand (/b/{lien}) : catalogue, devis, commande
 * en paiement à la livraison. Sans compte client.
 */
class ShopController extends Controller
{
    public function __construct(private readonly ShopCheckout $checkout) {}

    public function show(string $slug): JsonResponse
    {
        $merchant = $this->merchant($slug);
        $company = $merchant->company;

        return response()->json(['data' => [
            'name' => $merchant->business_name,
            'intro' => $merchant->shop_intro,
            'contact_phone' => $merchant->whatsapp_phone ?? $merchant->phone,
            'fee_payer' => ShopCheckout::feePayer($merchant)->value,
            'delivery_company' => ['name' => $company->name, 'logo_url' => Branding::logoUrl($company)],
            'products' => $this->checkout->catalog($merchant)->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price' => $p->price,
                'photo_url' => $p->photoUrl(),
                'available' => ShopCheckout::available($p),
            ])->values(),
            'zones' => $this->zones($merchant)->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->fullName()])->values(),
        ]]);
    }

    public function quote(Request $request, string $slug): JsonResponse
    {
        $merchant = $this->merchant($slug);
        $data = $request->validate([
            'zone_id' => ['required', 'integer', $this->zoneRule($merchant)],
            ...$this->itemRules(),
        ], [], ['zone_id' => 'commune']);

        return response()->json(['data' => $this->checkout->quote($merchant, $data['items'], Zone::forCompany($merchant->company_id)->findOrFail($data['zone_id']))]);
    }

    public function order(Request $request, string $slug): JsonResponse
    {
        $merchant = $this->merchant($slug);

        // Champ invisible rempli par les robots
        abort_if(filled($request->input('website')), 422, 'Commande refusée.');

        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', new PhoneNumber],
            'zone_id' => ['required', 'integer', $this->zoneRule($merchant)],
            'address' => ['required', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            ...$this->itemRules(),
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'zone_id' => 'commune', 'address' => 'adresse', 'landmark' => 'repère']);

        $order = $this->checkout->order($merchant, $data);

        return response()->json(['data' => [
            'tracking_code' => $order->tracking_code,
            'total' => $order->cod_amount,
            'description' => $order->description,
        ]], 201);
    }

    private function merchant(string $slug): Merchant
    {
        $merchant = Merchant::query()->where('shop_slug', mb_strtolower($slug))->where('shop_enabled', true)->first();
        abort_if($merchant === null || ! $merchant->isActive() || ! $merchant->company?->isActive(), 404, 'Boutique introuvable.');

        return $merchant;
    }

    /**
     * Communes et quartiers livrés (hors expéditions vers l'intérieur du pays).
     *
     * @return Collection<int, Zone>
     */
    private function zones(Merchant $merchant)
    {
        return Zone::forCompany($merchant->company_id)->active()->where('is_shipping', false)->with('parent')->get()
            ->sortBy(fn (Zone $z) => $z->fullName(), SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    private function zoneRule(Merchant $merchant): mixed
    {
        return Rule::exists('zones', 'id')->where(fn ($q) => $q->where('company_id', $merchant->company_id)->where('is_active', true)->where('is_shipping', false));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.ShopCheckout::MAX_QUANTITY],
        ];
    }
}
