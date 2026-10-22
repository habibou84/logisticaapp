<?php

namespace App\Services\Shop;

use App\Enums\FeePayer;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\ShopOrderReceived;
use App\Services\Orders\OrderService;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Commande passée sur la page du marchand (/b/{lien}) : le client choisit des produits,
 * sa commune, et paie à la livraison. La commande devient une course ordinaire.
 *
 * Stock : un produit dont le stock est suivi (chez le marchand ou dans un entrepôt) est
 * réservé, et le colis part de l'emplacement qui a tout le nécessaire (l'entrepôt en
 * priorité) ; un produit sans stock suivi est un simple article, ramassé chez le marchand.
 */
class ShopCheckout
{
    public const MAX_QUANTITY = 50;

    public function __construct(
        private readonly OrderService $orders,
        private readonly PricingService $pricing,
    ) {}

    /**
     * Produits proposés sur la page, avec leur stock.
     *
     * @return Collection<int, Product>
     */
    public function catalog(Merchant $merchant): Collection
    {
        return Product::forCompany($merchant->company_id)
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->where('shop_visible', true)
            ->with('levels.location.hub')
            ->orderBy('name')
            ->get();
    }

    /**
     * Qui paie la livraison des commandes de la boutique (réglage propre à la boutique).
     */
    public static function feePayer(Merchant $merchant): FeePayer
    {
        return $merchant->shop_fee_payer ?? FeePayer::Recipient;
    }

    /**
     * Quantité qu'on peut commander : null si le stock n'est pas suivi.
     */
    public static function available(Product $product): ?int
    {
        return $product->levels->isEmpty() ? null : max(0, $product->available());
    }

    /**
     * Devis : montant des articles, livraison, total à payer au livreur.
     *
     * @param  list<array{product_id: int, quantity: int}>  $rows
     * @return array{items_total: int, delivery_fee: int, fee_payer: string, total: int}
     */
    public function quote(Merchant $merchant, array $rows, Zone $zone): array
    {
        $plan = $this->plan($merchant, $rows);
        $fee = $this->pricing->quote($merchant, $plan['pickup_zone'], $zone)->total();
        $payer = self::feePayer($merchant);

        return [
            'items_total' => $plan['items_total'],
            'delivery_fee' => $fee,
            'fee_payer' => $payer->value,
            'total' => Order::computeCodAmount($plan['items_total'], $fee, $payer),
        ];
    }

    /**
     * Crée la course et prévient le marchand.
     *
     * @param  array{name: string, phone: string, zone_id: int, address: string, landmark?: ?string, note?: ?string, items: list<array{product_id: int, quantity: int}>}  $data
     */
    public function order(Merchant $merchant, array $data): Order
    {
        $order = DB::transaction(function () use ($merchant, $data) {
            $plan = $this->plan($merchant, $data['items']);

            return $this->orders->create($this->actor($merchant), $merchant, [
                'pickup_hub_id' => $plan['hub_id'],
                'recipient_name' => $data['name'],
                'recipient_phone' => $data['phone'],
                'delivery_zone_id' => $data['zone_id'],
                'delivery_address' => $data['address'],
                'delivery_landmark' => $data['landmark'] ?? null,
                'merchant_note' => filled($data['note'] ?? null) ? 'Message du client : '.$data['note'] : null,
                'items' => $plan['items'],
                'fee_payer' => self::feePayer($merchant)->value,
            ], 'shop');
        });

        $users = User::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('status', 'active')->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, new ShopOrderReceived($order));
        }

        return $order;
    }

    /**
     * Articles, lieu de départ et zone de ramassage de la commande.
     *
     * @param  list<array{product_id: int, quantity: int}>  $rows
     * @return array{hub_id: ?int, pickup_zone: Zone, items: list<array<string, mixed>>, items_total: int}
     */
    private function plan(Merchant $merchant, array $rows): array
    {
        $quantities = [];
        foreach ($rows as $row) {
            $quantities[(int) $row['product_id']] = min(self::MAX_QUANTITY, ($quantities[(int) $row['product_id']] ?? 0) + (int) $row['quantity']);
        }

        $products = $this->catalog($merchant)->whereIn('id', array_keys($quantities))->keyBy('id');
        if ($products->count() !== count($quantities)) {
            throw new BusinessRuleException('Un article de votre panier n\'est plus proposé. Actualisez la page.', 'items');
        }

        $tracked = $products->filter(fn (Product $p) => $p->levels->isNotEmpty());
        $hasFreeItems = $tracked->count() < $products->count();
        $location = $tracked->isEmpty() ? null : $this->location($tracked, $quantities, $hasFreeItems);

        $hub = $location?->hub;
        $pickupZone = $hub ? Zone::forCompany($merchant->company_id)->find($hub->zone_id) : $merchant->pickupZone;
        if ($pickupZone === null) {
            throw new BusinessRuleException('Cette boutique ne peut pas prendre de commande pour le moment.');
        }

        $items = $products->map(fn (Product $p) => $p->levels->isNotEmpty()
            ? ['product_id' => $p->id, 'quantity' => $quantities[$p->id]]
            : ['product_id' => null, 'label' => $p->name, 'quantity' => $quantities[$p->id], 'unit_price' => $p->price])->values()->all();

        return [
            'hub_id' => $hub?->id,
            'pickup_zone' => $pickupZone,
            'items' => $items,
            'items_total' => (int) $products->sum(fn (Product $p) => $p->price * $quantities[$p->id]),
        ];
    }

    /**
     * Emplacement qui a tous les produits suivis en quantité suffisante : un entrepôt
     * d'abord ; seulement chez le marchand s'il y a aussi des articles sans stock suivi.
     *
     * @param  Collection<int, Product>  $tracked
     * @param  array<int, int>  $quantities
     */
    private function location(Collection $tracked, array $quantities, bool $merchantOnly): mixed
    {
        $candidates = $tracked->flatMap(fn (Product $p) => $p->levels->pluck('location'))->unique('id')
            ->filter(fn ($location) => ! $merchantOnly || ! $location->isWarehouse())
            ->sortBy(fn ($location) => $location->isWarehouse() ? 0 : 1);

        foreach ($candidates as $location) {
            $enough = $tracked->every(function (Product $p) use ($location, $quantities) {
                $level = $p->levels->first(fn (StockLevel $l) => $l->stock_location_id === $location->id);

                return $level !== null && $level->available() >= $quantities[$p->id];
            });

            if ($enough) {
                return $location;
            }
        }

        // Explication : article épuisé, quantité trop grande, ou articles à des endroits différents
        foreach ($tracked as $product) {
            $available = max(0, $product->available());
            if ($available < $quantities[$product->id]) {
                throw new BusinessRuleException($available === 0
                    ? "« {$product->name} » est épuisé."
                    : "Il ne reste que {$available} « {$product->name} ».", 'items');
            }
        }

        throw new BusinessRuleException('Ces articles ne peuvent pas partir ensemble : passez une commande par article.', 'items');
    }

    /**
     * Compte au nom duquel la course est créée : le gérant de la boutique.
     */
    private function actor(Merchant $merchant): User
    {
        return User::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('status', 'active')
            ->get()->sortByDesc(fn (User $u) => $u->hasRole(Role::MerchantOwner->value))->first()
            ?? throw new BusinessRuleException('Cette boutique ne peut pas prendre de commande pour le moment.');
    }
}
