<?php

namespace App\Services\Orders;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Destinations\Destinations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Notes de suivi de l'équipe sur une course (« En attente de l'adresse », « Réceptionne le … ») et reconduction d'une
 * course (pli revenu pour un document à corriger, entreprise injoignable…) : une nouvelle course, liée à la première.
 */
class OrderFollowUps
{
    public function __construct(
        private readonly OrderJournal $journal,
        private readonly OrderService $orders,
        private readonly OrderWorkflow $workflow,
    ) {}

    /**
     * Pose (ou lève, motif null) la note de suivi : mise en attente, date de réception, fiche de l'annuaire à revoir.
     */
    public function set(User $actor, Order $order, ?int $reasonId, ?string $note = null, ?string $date = null): Order
    {
        if ($reasonId === null) {
            if ($order->follow_up_reason_id !== null) {
                $order->forceFill(['follow_up_reason_id' => null, 'follow_up_note' => null, 'follow_up_at' => null, 'follow_up_by' => null])->save();
                $this->journal->record($order, $actor, OrderEventType::Note, ['note' => 'Suivi levé', 'visible_to_merchant' => false]);
            }

            return $order->fresh();
        }

        $reason = IncidentReason::availableTo($order->company_id)->where('applies_to', 'follow_up')->find($reasonId)
            ?? throw new BusinessRuleException('Note de suivi inconnue.', 'reason_id');
        $note = filled($note) ? trim($note) : null;
        if ($reason->requires_note && $note === null) {
            throw new BusinessRuleException("« {$reason->label} » : précisez dans la note.", 'note');
        }
        $day = null;
        if ($reason->requires_date) {
            $day = $date ? CarbonImmutable::parse($date)->startOfDay() : null;
            if ($day === null || $day->isPast() && ! $day->isToday()) {
                throw new BusinessRuleException("« {$reason->label} » : indiquez une date à venir.", 'date');
            }
        }

        DB::transaction(function () use ($actor, $order, $reason, $note, $day) {
            $order->forceFill([
                'follow_up_reason_id' => $reason->id, 'follow_up_note' => $note,
                'follow_up_at' => now(), 'follow_up_by' => $actor->id,
            ])->save();
            $this->journal->record($order, $actor, OrderEventType::Note, [
                'note' => 'Suivi : '.$reason->label.($day ? ' '.$day->format('d/m/Y') : '').($note ? " — {$note}" : ''),
                'visible_to_merchant' => (bool) $reason->visible_to_client,
            ]);
        });

        // « Réceptionne le … » : la livraison passe à cette date (report si la livraison avait échoué)
        if ($day !== null) {
            if ($order->status === OrderStatus::DeliveryFailed) {
                $order = $this->workflow->transition($actor, $order, OrderStatus::Rescheduled, ['rescheduled_to' => $day->toDateString(), 'note' => $reason->label]);
            } elseif (! $order->status->isFinal()) {
                $order->forceFill(['delivery_scheduled_date' => $day->toDateString()])->save();
            }
        }
        app(Destinations::class)->flag($order, $reason);

        return $order->fresh();
    }

    /** Course arrêtée : la note de suivi n'a plus d'objet */
    public function clearOnProgress(Order $order): void
    {
        if ($order->follow_up_reason_id !== null && ($order->status->isFinal() || $order->status === OrderStatus::OutForDelivery)) {
            $order->forceFill(['follow_up_reason_id' => null, 'follow_up_note' => null, 'follow_up_at' => null, 'follow_up_by' => null])->save();
        }
    }

    /**
     * Reconduire : nouvelle course identique (destinataire, adresse, envoi, département), à une autre date ; la
     * première reste telle quelle (et comptée au forfait si elle y était).
     */
    public function renew(User $actor, Order $order, ?string $date = null, ?string $note = null): Order
    {
        if (! in_array($order->status, [OrderStatus::Returned, OrderStatus::Cancelled, OrderStatus::DeliveryFailed], true)) {
            throw new BusinessRuleException('Seule une course retournée, annulée ou en échec se reconduit.', 'status');
        }
        $merchant = Merchant::withoutGlobalScopes()->findOrFail($order->merchant_id);
        $data = collect($order->only([
            'activity', 'recipient_name', 'recipient_phone', 'recipient_phone2', 'delivery_zone_id', 'delivery_address',
            'delivery_landmark', 'delivery_lat', 'delivery_lng', 'delivery_time_slot', 'merchant_reference', 'description',
            'package_size', 'weight_kg', 'is_fragile', 'is_express', 'shipment_type', 'shipment_quantity', 'delivery_delay',
            'department_id', 'to_department_id', 'requested_by', 'destination_id', 'fee_payer',
        ]))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->filter(fn ($v) => $v !== null)->all();
        $data['items_amount'] = (int) $order->items_amount;
        $data['delivery_scheduled_date'] = $date;
        $data['merchant_note'] = trim(($note ? "{$note} · " : '')."Reconduite de {$order->tracking_code}");

        return DB::transaction(function () use ($actor, $merchant, $data, $order) {
            $renewed = $this->orders->create($actor, $merchant, $data, 'renewal');
            $renewed->forceFill(['renewed_from_id' => $order->id])->save();
            $this->journal->record($order, $actor, OrderEventType::Note, ['note' => "Reconduite : nouvelle course {$renewed->tracking_code}"]);

            return $renewed->fresh();
        });
    }
}
