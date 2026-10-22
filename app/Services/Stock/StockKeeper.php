<?php

namespace App\Services\Stock;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\StockAlert;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Tous les mouvements de stock passent par ici : chaque mouvement met à jour le
 * niveau (verrouillé) et s'inscrit au journal immuable stock_movements.
 *
 * Disponible = en stock − réservé. Une course réserve ses articles à la création,
 * les sort du stock à la livraison et les libère si elle est annulée, refusée
 * ou si le colis revient.
 */
class StockKeeper
{
    /**
     * Emplacement du stock d'un marchand : chez lui (hub null) ou dans un entrepôt.
     */
    public function location(Merchant $merchant, ?Hub $hub = null): StockLocation
    {
        return StockLocation::forCompany($merchant->company_id)->firstOrCreate([
            'merchant_id' => $merchant->id,
            'hub_id' => $hub?->id,
        ], ['company_id' => $merchant->company_id]);
    }

    public function receive(?User $actor, Product $product, StockLocation $location, int $quantity, ?string $note = null): StockMovement
    {
        $this->ensurePositive($quantity);

        return $this->move($product, $location, StockMovementType::Receipt, $quantity, 0, $actor, null, $note);
    }

    public function withdraw(?User $actor, Product $product, StockLocation $location, int $quantity, ?string $note = null): StockMovement
    {
        $this->ensurePositive($quantity);

        return $this->move($product, $location, StockMovementType::Withdrawal, -$quantity, 0, $actor, null, $note, function (StockLevel $level) use ($quantity) {
            if ($level->available() < $quantity) {
                throw new BusinessRuleException("Seulement {$level->available()} disponible(s) : le reste est réservé par des courses en cours.", 'quantity');
            }
        });
    }

    /**
     * Inventaire : la quantité comptée remplace la quantité en stock.
     */
    public function count(?User $actor, Product $product, StockLocation $location, int $counted, ?string $note = null): ?StockMovement
    {
        if ($counted < 0) {
            throw new BusinessRuleException('La quantité comptée ne peut pas être négative.', 'quantity');
        }

        return DB::transaction(function () use ($actor, $product, $location, $counted, $note) {
            $level = $this->lockedLevel($product, $location);

            if ($counted < $level->reserved) {
                throw new BusinessRuleException("{$level->reserved} article(s) sont réservés par des courses en cours : annulez-les ou comptez au moins cette quantité.", 'quantity');
            }

            $delta = $counted - $level->on_hand;

            return $delta === 0 ? null : $this->move($product, $location, StockMovementType::Adjustment, $delta, 0, $actor, null, $note ?? 'Inventaire');
        });
    }

    /**
     * Réserve les articles d'une course (à sa création).
     */
    public function reserve(Order $order, OrderItem $item, ?User $actor): void
    {
        $product = $item->product;
        $location = $item->location;

        $this->move($product, $location, StockMovementType::Reservation, 0, $item->quantity, $actor, $order, null, function (StockLevel $level) use ($item, $product, $location) {
            if ($level->available() < $item->quantity) {
                $where = $location->isWarehouse() ? 'à l\'entrepôt' : 'dans votre stock';
                throw new BusinessRuleException("Stock insuffisant pour « {$product->name} » : {$level->available()} disponible(s) {$where}.", 'items');
            }
        });

        $item->forceFill(['stock_state' => 'reserved'])->save();
    }

    /**
     * Course livrée : les articles réservés sortent définitivement du stock.
     */
    public function ship(Order $order, ?User $actor): void
    {
        foreach ($this->reservedItems($order) as $item) {
            $this->move($item->product, $item->location, StockMovementType::Shipment, -$item->quantity, -$item->quantity, $actor, $order);
            $item->forceFill(['stock_state' => 'shipped'])->save();
        }
    }

    /**
     * Colis perdu : les articles sortent du stock (ajustement), sans livraison.
     */
    public function lose(Order $order, ?User $actor): void
    {
        foreach ($this->reservedItems($order) as $item) {
            $this->move($item->product, $item->location, StockMovementType::Adjustment, -$item->quantity, -$item->quantity, $actor, $order, 'Colis perdu');
            $item->forceFill(['stock_state' => 'shipped'])->save();
        }
    }

    /**
     * Course annulée, refusée ou colis revenu : les articles redeviennent disponibles.
     */
    public function release(Order $order, ?User $actor, string $note): void
    {
        foreach ($this->reservedItems($order) as $item) {
            $this->move($item->product, $item->location, StockMovementType::Release, 0, -$item->quantity, $actor, $order, $note);
            $item->forceFill(['stock_state' => 'released'])->save();
        }
    }

    /**
     * Effets d'un changement de statut de la course sur ses articles réservés.
     */
    public function onTransition(Order $order, OrderStatus $to, ?User $actor): void
    {
        match ($to) {
            OrderStatus::Delivered => $this->ship($order, $actor),
            OrderStatus::Cancelled => $this->release($order, $actor, 'Course annulée'),
            OrderStatus::Rejected => $this->release($order, $actor, 'Course refusée'),
            OrderStatus::Returned => $this->release($order, $actor, $order->fromWarehouse() ? 'Colis remis en stock' : 'Colis retourné au marchand'),
            OrderStatus::Lost => $this->lose($order, $actor),
            default => null,
        };
    }

    /**
     * Recalcule les niveaux depuis le journal (contrôle ou réparation du cache).
     */
    public function rebuild(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $totals = StockMovement::forCompany($product->company_id)
                ->where('product_id', $product->id)
                ->groupBy('stock_location_id')
                ->selectRaw('stock_location_id, SUM(on_hand_change) AS on_hand, SUM(reserved_change) AS reserved')
                ->get();

            foreach ($totals as $row) {
                StockLevel::updateOrCreate(
                    ['product_id' => $product->id, 'stock_location_id' => $row->stock_location_id],
                    ['on_hand' => (int) $row->on_hand, 'reserved' => (int) $row->reserved],
                );
            }
        });
    }

    /**
     * @param  (callable(StockLevel): void)|null  $check  contrôle avant mouvement (niveau verrouillé)
     */
    private function move(
        Product $product,
        StockLocation $location,
        StockMovementType $type,
        int $onHandChange,
        int $reservedChange,
        ?User $actor,
        ?Order $order = null,
        ?string $note = null,
        ?callable $check = null,
    ): StockMovement {
        if ($product->merchant_id !== $location->merchant_id) {
            throw new BusinessRuleException('Ce produit n\'appartient pas à ce marchand.', 'product_id');
        }

        return DB::transaction(function () use ($product, $location, $type, $onHandChange, $reservedChange, $actor, $order, $note, $check) {
            $level = $this->lockedLevel($product, $location);
            $before = $this->totalAvailable($product);

            if ($check) {
                $check($level);
            }

            $level->on_hand += $onHandChange;
            $level->reserved = max(0, $level->reserved + $reservedChange);
            $level->save();

            $movement = StockMovement::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'stock_location_id' => $location->id,
                'type' => $type,
                'on_hand_change' => $onHandChange,
                'reserved_change' => $reservedChange,
                'on_hand_after' => $level->on_hand,
                'order_id' => $order?->id,
                'user_id' => $actor?->id,
                'note' => filled($note) ? mb_substr(trim($note), 0, 255) : null,
            ]);

            $this->alertIfLow($product, $location, $before, $this->totalAvailable($product));

            return $movement;
        });
    }

    private function lockedLevel(Product $product, StockLocation $location): StockLevel
    {
        StockLevel::firstOrCreate(['product_id' => $product->id, 'stock_location_id' => $location->id]);

        return StockLevel::query()
            ->where('product_id', $product->id)
            ->where('stock_location_id', $location->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function totalAvailable(Product $product): int
    {
        return (int) StockLevel::where('product_id', $product->id)->sum(DB::raw('on_hand - reserved'));
    }

    /**
     * @return iterable<OrderItem>
     */
    private function reservedItems(Order $order): iterable
    {
        return $order->items()->where('stock_state', 'reserved')->with(['product', 'location'])->lockForUpdate()->get();
    }

    /**
     * Alerte quand le disponible passe sous le seuil, puis quand il tombe à zéro (une fois par franchissement).
     */
    private function alertIfLow(Product $product, StockLocation $location, int $before, int $after): void
    {
        $threshold = $product->low_stock_threshold;
        $crossedThreshold = $after <= $threshold && $before > $threshold;
        $ranOut = $after <= 0 && $before > 0;
        if ($threshold === null || (! $crossedThreshold && ! $ranOut)) {
            return;
        }

        $title = $after <= 0 ? "Rupture de stock : {$product->name}" : "Stock bas : {$product->name}";
        $body = "Il reste {$after} article(s) disponible(s) (seuil : {$product->low_stock_threshold}).";

        DB::afterCommit(function () use ($product, $location, $title, $body, $after) {
            app(WebhookDispatcher::class)->onLowStock($product, $after);

            $users = User::forCompany($product->company_id)
                ->where('status', 'active')
                ->where(fn ($q) => $q->where('merchant_id', $product->merchant_id)
                    // Stock à l'entrepôt : les agents de dépôt sont aussi prévenus
                    ->when($location->isWarehouse(), fn ($q) => $q->orWhereHas('roles', fn ($r) => $r->whereIn('name', [Role::Admin->value, Role::HubAgent->value]))))
                ->get();

            if ($users->isNotEmpty()) {
                Notification::send($users, new StockAlert($product, $title, $body));
            }
        });
    }

    private function ensurePositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new BusinessRuleException('La quantité doit être supérieure à zéro.', 'quantity');
        }
    }
}
