<?php

namespace App\Services\Onboarding;

use App\Enums\FeePayer;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\CourierEarning;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderExpense;
use App\Models\User;
use App\Models\Zone;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Course d'essai de la mise en route : une vraie course, d'une « Boutique d'essai » vers l'administrateur lui-même
 * (il reçoit les messages du destinataire), qu'on fait avancer jusqu'à la livraison avec le code, puis qu'on efface
 * avec tout ce qu'elle a produit (journal, gains, écritures, argent encaissé) pour ne fausser ni la caisse ni les
 * statistiques. L'argent encaissé ne doit pas être versé à la caisse : une fois versé, la course ne s'efface plus.
 */
class TestOrder
{
    public const ITEMS_AMOUNT = 1000;

    public function __construct(private readonly OrderService $orders) {}

    public function current(Company $company): ?Order
    {
        return Order::withoutGlobalScopes()->where('company_id', $company->id)->where('is_test', true)->latest('id')->first();
    }

    public function start(User $admin): Order
    {
        $company = $admin->company;
        if ($existing = $this->current($company)) {
            return $existing;
        }

        $zone = Zone::withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)
            ->where('is_shipping', false)->whereNull('parent_id')->orderBy('id')->first();
        if ($zone === null) {
            throw new BusinessRuleException('Ajoutez d\'abord une zone desservie.', 'zone');
        }

        $merchant = Merchant::withoutGlobalScopes()->where('company_id', $company->id)->where('is_test', true)->first()
            ?? tap(new Merchant([
                'company_id' => $company->id,
                'business_name' => 'Boutique d\'essai',
                'phone' => 'essai-'.$company->id,
                'pickup_zone_id' => $zone->id,
                'pickup_address' => $company->address ?: 'Adresse d\'essai',
                'status' => 'active',
                'default_fee_payer' => FeePayer::Merchant,
                'notes' => 'Créée par la mise en route pour la course d\'essai.',
            ]), fn (Merchant $m) => $m->forceFill(['is_test' => true])->save());

        $order = $this->orders->create($admin, $merchant, [
            'recipient_name' => 'Destinataire d\'essai ('.$admin->name.')',
            'recipient_phone' => $admin->phone,
            'delivery_zone_id' => $merchant->pickup_zone_id,
            'delivery_address' => 'Adresse d\'essai',
            'description' => 'Course d\'essai de la mise en route : elle sera effacée.',
            'items_amount' => self::ITEMS_AMOUNT,
        ]);
        $order->forceFill(['is_test' => true])->save();

        return $order->fresh();
    }

    /**
     * Les étapes de la course d'essai, cochées d'après son état.
     *
     * @return array<string, mixed>|null
     */
    public function progress(Company $company): ?array
    {
        $order = $this->current($company);
        if ($order === null) {
            return null;
        }
        $after = fn (OrderStatus ...$s) => ! in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed, ...$s], true);
        $collected = CashCollection::withoutGlobalScopes()->where('order_id', $order->id)->first();

        return [
            'order_id' => $order->id,
            'tracking_code' => $order->tracking_code,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'checkpoints' => [
                ['label' => 'Course créée', 'done' => true],
                ['label' => 'Course validée', 'done' => $order->status !== OrderStatus::Pending],
                ['label' => 'Livreur de ramassage choisi', 'done' => $order->pickup_courier_id !== null || $after()],
                ['label' => 'Colis récupéré par le livreur (dans son application)', 'done' => $order->picked_up_at !== null],
                ['label' => 'Livreur de livraison choisi', 'done' => $order->delivery_courier_id !== null],
                ['label' => 'Colis livré avec le code reçu par le destinataire', 'done' => $order->status === OrderStatus::Delivered],
                ['label' => 'Argent encaissé par le livreur (visible à la caisse)', 'done' => $collected !== null],
            ],
            'delivered' => $order->status === OrderStatus::Delivered,
            'paid_in' => $collected?->remittance_id !== null,
        ];
    }

    /**
     * Efface la course d'essai et ce qu'elle a produit ; garde la trace qu'elle a été livrée.
     */
    public function clean(Company $company): void
    {
        $order = $this->current($company);
        if ($order === null) {
            return;
        }

        DB::transaction(function () use ($company, $order) {
            $collection = CashCollection::withoutGlobalScopes()->where('order_id', $order->id)->first();
            $paid = $collection?->remittance_id !== null
                || CourierEarning::withoutGlobalScopes()->where('order_id', $order->id)->whereNotNull('payout_id')->exists()
                || MerchantLedgerEntry::withoutGlobalScopes()->where('order_id', $order->id)->whereNotNull('payout_id')->exists();
            if ($paid) {
                throw new BusinessRuleException('L\'argent de la course d\'essai a déjà été versé ou payé : elle ne peut plus être effacée. Annulez-la plutôt, et faites l\'opération inverse à la caisse.', 'order');
            }

            if ($order->status === OrderStatus::Delivered) {
                $company->forceFill(['setup_test_delivered_at' => now()])->save();
            }
            // Écritures de la seule course d'essai : effacées sans passer par la règle « jamais supprimées »
            $collection?->delete();
            CourierEarning::withoutGlobalScopes()->where('order_id', $order->id)->delete();
            MerchantLedgerEntry::withoutGlobalScopes()->where('order_id', $order->id)->delete();
            OrderExpense::withoutGlobalScopes()->where('order_id', $order->id)->delete();
            $merchant = $order->merchant_id;
            $order->forceDelete();

            // Boutique d'essai sans autre course : effacée elle aussi
            $test = Merchant::withoutGlobalScopes()->where('is_test', true)->find($merchant);
            if ($test && ! Order::withoutGlobalScopes()->where('merchant_id', $test->id)->exists()) {
                try {
                    DB::transaction(fn () => $test->forceDelete());
                } catch (Throwable) {
                    $test->delete();
                }
            }
        });
    }
}
