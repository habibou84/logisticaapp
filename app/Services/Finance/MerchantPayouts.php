<?php

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Enums\NotificationEvent;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\User;
use App\Services\Messaging\Messenger;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reversements aux marchands. Un reversement regroupe les écritures non encore
 * reversées, sauf celles des courses dont l'argent est encore chez un livreur :
 * on ne reverse que ce que la caisse a réellement reçu.
 */
class MerchantPayouts
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /**
     * Écritures prêtes à être reversées.
     */
    public function eligibleEntries(?Merchant $merchant = null): Builder
    {
        return MerchantLedgerEntry::query()
            ->when($merchant, fn ($q) => $q->where('merchant_id', $merchant->id))
            ->whereNull('payout_id')
            ->where('type', '!=', LedgerEntryType::Payout->value)
            // Course dont l'argent est encore chez le livreur : encaissement et frais attendent ensemble
            ->whereDoesntHave('order.cashCollection', fn ($q) => $q->inCourierHands());
    }

    /**
     * Solde du marchand : disponible, en attente de versement des livreurs, total.
     *
     * @return array{available: int, pending_cash: int, unpaid_total: int, balance: int}
     */
    public function summary(Merchant $merchant): array
    {
        $unpaid = (int) MerchantLedgerEntry::where('merchant_id', $merchant->id)
            ->whereNull('payout_id')
            ->where('type', '!=', LedgerEntryType::Payout->value)
            ->sum('amount');
        $available = (int) $this->eligibleEntries($merchant)->sum('amount');

        return [
            'available' => $available,
            'pending_cash' => $unpaid - $available,
            'unpaid_total' => $unpaid,
            'balance' => (int) MerchantLedgerEntry::where('merchant_id', $merchant->id)->sum('amount'),
        ];
    }

    /**
     * Soldes de tous les marchands de l'entreprise en deux requêtes groupées.
     *
     * @return array<int, array{available: int, pending_cash: int, unpaid_total: int}>
     */
    public function summaries(): array
    {
        $unpaid = MerchantLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('type', '!=', LedgerEntryType::Payout->value)
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, SUM(amount) AS total')
            ->pluck('total', 'merchant_id');

        $available = $this->eligibleEntries()
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, SUM(amount) AS total')
            ->pluck('total', 'merchant_id');

        return $unpaid->map(fn ($total, $merchantId) => [
            'available' => (int) ($available[$merchantId] ?? 0),
            'pending_cash' => (int) $total - (int) ($available[$merchantId] ?? 0),
            'unpaid_total' => (int) $total,
        ])->all();
    }

    public function create(User $actor, Merchant $merchant, ?string $notes = null): MerchantPayout
    {
        return DB::transaction(function () use ($actor, $merchant, $notes) {
            $entries = $this->eligibleEntries($merchant)->lockForUpdate()->get();

            if ($entries->isEmpty()) {
                throw new BusinessRuleException('Rien à reverser pour ce marchand pour le moment.', 'merchant_id');
            }

            $sum = fn (array $types) => $entries->filter(fn ($e) => in_array($e->type, $types, true))->sum('amount');

            $payout = MerchantPayout::create([
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
                'reference' => 'TMP-'.Str::random(20),
                'period_start' => $entries->min('created_at'),
                'period_end' => $entries->max('created_at'),
                'total_collected' => $sum([LedgerEntryType::CodCredit]),
                'total_fees' => -$sum([LedgerEntryType::DeliveryFee, LedgerEntryType::ReturnFee]),
                'total_shipping_fees' => -$sum([LedgerEntryType::ShippingFee]),
                'total_other_fees' => -$sum([LedgerEntryType::OtherFee]),
                'total_storage_fees' => -$sum([LedgerEntryType::StorageFee]),
                'total_adjustments' => $sum([LedgerEntryType::Adjustment]),
                'net_amount' => $entries->sum('amount'),
                'status' => PayoutStatus::Draft,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);
            $payout->forceFill(['reference' => sprintf('RV-%s-%05d', now()->format('ymd'), $payout->id)])->save();

            MerchantLedgerEntry::whereIn('id', $entries->modelKeys())->get()
                ->each(fn (MerchantLedgerEntry $e) => $e->forceFill(['payout_id' => $payout->id])->save());

            return $payout;
        });
    }

    public function markPaid(User $actor, MerchantPayout $payout, PaymentMethod $method, ?string $reference = null): MerchantPayout
    {
        return DB::transaction(function () use ($actor, $payout, $method, $reference) {
            $payout = MerchantPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->ensureDraft($payout);

            $payout->forceFill([
                'status' => PayoutStatus::Paid,
                'method' => $method,
                'transaction_ref' => $reference,
                'paid_by' => $actor->id,
                'paid_at' => now(),
            ])->save();

            // Solde le relevé dans le grand livre
            MerchantLedgerEntry::create([
                'company_id' => $payout->company_id,
                'merchant_id' => $payout->merchant_id,
                'type' => LedgerEntryType::Payout,
                'amount' => -$payout->net_amount,
                'payout_id' => $payout->id,
                'description' => "Reversement {$payout->reference} ({$method->label()})",
                'created_by' => $actor->id,
            ]);

            $merchant = $payout->merchant;
            $this->messenger->toMerchant($merchant, NotificationEvent::PayoutPaid, WhatsAppTemplate::PayoutPaid, [
                $merchant->business_name, $payout->reference, Money::format($payout->net_amount), $method->label(),
            ]);
            DB::afterCommit(fn () => $this->webhooks->onPayoutPaid($payout));

            return $payout;
        });
    }

    public function cancel(MerchantPayout $payout): MerchantPayout
    {
        return DB::transaction(function () use ($payout) {
            $payout = MerchantPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->ensureDraft($payout);

            $payout->entries->each(fn (MerchantLedgerEntry $e) => $e->forceFill(['payout_id' => null])->save());
            $payout->forceFill(['status' => PayoutStatus::Cancelled])->save();

            return $payout;
        });
    }

    public function adjust(User $actor, Merchant $merchant, int $amount, string $description): MerchantLedgerEntry
    {
        if ($amount === 0) {
            throw new BusinessRuleException('Le montant de l\'ajustement ne peut pas être nul.', 'amount');
        }

        return MerchantLedgerEntry::create([
            'company_id' => $merchant->company_id,
            'merchant_id' => $merchant->id,
            'type' => LedgerEntryType::Adjustment,
            'amount' => $amount,
            'description' => $description,
            'created_by' => $actor->id,
        ]);
    }

    private function ensureDraft(MerchantPayout $payout): void
    {
        if ($payout->status !== PayoutStatus::Draft) {
            throw new BusinessRuleException("Ce reversement est déjà « {$payout->status->label()} ».", 'status');
        }
    }
}
