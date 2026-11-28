<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\ReturnCause;
use App\Exceptions\BusinessRuleException;
use App\Models\FieldReportReview;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderProposal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Report ou retour signalé par un livreur avec un motif « à valider » : la course reste en échec de livraison
 * jusqu'à ce que l'équipe ou le client confirme. Validé, le report ou le retour prend effet ; refusé, l'équipe décide
 * de la suite (« Que faire ? »). Toute autre décision prise sur la course remplace la proposition.
 */
class OrderProposals
{
    public function __construct(
        private readonly OrderJournal $journal,
    ) {}

    public function propose(User $courier, Order $order, OrderEvent $event, IncidentReason $reason, ?string $date, ?string $time = null): OrderProposal
    {
        // Une seule proposition en cours par course : la nouvelle remplace l'ancienne
        $this->settle($order, $courier);

        return OrderProposal::create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'order_event_id' => $event->id,
            'incident_reason_id' => $reason->id,
            'proposed_by' => $courier->id,
            'kind' => $reason->requires_date ? OrderProposal::RESCHEDULE : OrderProposal::RETURN,
            'proposed_date' => $reason->requires_date ? $date : null,
            'proposed_time' => $reason->requires_date ? $time : null,
            'status' => OrderProposal::PENDING,
        ]);
    }

    /**
     * Validation : le report (à la date proposée, ou à une autre si elle est passée ou corrigée) ou le retour prend
     * effet, comme si le motif n'avait rien demandé. Proposé avant le départ du livreur : sa mission est retirée.
     */
    public function approve(User $actor, Order $order, ?string $date = null, ?string $note = null, ?string $time = null): Order
    {
        $proposal = $this->pending($order);
        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($actor, $order, $proposal, $date, $note, $time) {
            $this->decide($actor, $proposal, OrderProposal::APPROVED, $note);
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status->isFinal() || $order->status === OrderStatus::OutForDelivery) {
                throw new BusinessRuleException('La course a avancé : ce n\'est plus à valider.', 'status');
            }
            $what = $proposal->kind === OrderProposal::RESCHEDULE ? 'Report validé' : 'Retour validé';

            // Proposé avant le départ : la livraison prévue est annulée (le livreur est prévenu)
            if ($order->activeAssignment(AssignmentType::Delivery) !== null) {
                $order = app(OrderDispatcher::class)->unassign($actor, $order, AssignmentType::Delivery, $this->line($actor, $what, $note));
            }

            if ($proposal->kind === OrderProposal::RESCHEDULE) {
                $date ??= $proposal->proposed_date?->toDateString();
                if ($date === null || Carbon::parse($date)->isBefore(today())) {
                    throw new BusinessRuleException('La date proposée est passée : choisissez une nouvelle date.', 'date');
                }
                if ($order->return_requested) {
                    throw new BusinessRuleException('Le retour de ce colis est demandé : il ne peut plus être reporté.', 'date');
                }
                if ($order->attempts_count >= $order->max_attempts) {
                    throw new BusinessRuleException('Nombre maximal de tentatives atteint : organisez le retour du colis.', 'date');
                }
                $order = $this->workflow()->transition($actor, $order, OrderStatus::Rescheduled, [
                    'rescheduled_to' => $date,
                    'delivery_time' => $time ?? $proposal->proposed_time,
                    'note' => $this->line($actor, $what, $note),
                    'proposal_approved' => true,
                ]);
            } else {
                $order->markForReturn(ReturnCause::Refused);
                $order->save();
                $this->journal->record($order, $actor, OrderEventType::ReturnRequested, [
                    'note' => $this->line($actor, $what, $note),
                ]);
            }

            $this->closeReport($actor, $proposal, $what.($note ? " : {$note}" : ''));

            return $order->fresh();
        });
    }

    /** Refus : la course reste en échec de livraison, l'équipe décide de la suite */
    public function reject(User $actor, Order $order, string $note): Order
    {
        $proposal = $this->pending($order);
        $note = trim($note);

        DB::transaction(function () use ($actor, $order, $proposal, $note) {
            $this->decide($actor, $proposal, OrderProposal::REJECTED, $note);
            $what = $proposal->kind === OrderProposal::RESCHEDULE ? 'Report' : 'Retour';
            $this->journal->record($order, $actor, OrderEventType::Note, [
                'note' => "{$what} signalé par {$proposal->proposer?->name} refusé".($actor->merchant_id !== null ? ' par le client' : '')." : {$note}",
                'visible_to_merchant' => $actor->merchant_id !== null,
            ]);
            // Refus du client : l'équipe doit encore décider, la remontée reste à traiter
            if ($actor->merchant_id === null) {
                $this->closeReport($actor, $proposal, "{$what} refusé : {$note}");
            }
        });

        return $order->fresh();
    }

    /** Une autre décision est prise sur la course (relivraison, retour, nouveau départ) : la proposition est remplacée */
    public function settle(Order $order, ?User $actor): void
    {
        OrderProposal::withoutGlobalScopes()->where('order_id', $order->id)->where('status', OrderProposal::PENDING)->update([
            'status' => OrderProposal::CLOSED,
            'decided_by' => $actor?->id,
            'decided_by_type' => $actor === null ? null : ($actor->merchant_id !== null ? 'merchant' : 'company'),
            'decided_at' => now(),
        ]);
    }

    /** L'équipe (dispatch) ou le client de la course (pas un simple lecteur) */
    public static function canDecide(User $user, Order $order): bool
    {
        if ($user->merchant_id !== null) {
            return $user->merchant_id === $order->merchant_id && $user->can('orders.create');
        }

        return $user->can('orders.dispatch');
    }

    private function workflow(): OrderWorkflow
    {
        return app(OrderWorkflow::class);
    }

    private function pending(Order $order): OrderProposal
    {
        return OrderProposal::withoutGlobalScopes()->where('order_id', $order->id)->pending()->latest('id')->first()
            ?? throw new BusinessRuleException('Rien à valider sur cette course : une décision a déjà été prise.', 'proposal');
    }

    private function decide(User $actor, OrderProposal $proposal, string $status, ?string $note): void
    {
        $proposal->forceFill([
            'status' => $status,
            'decided_by' => $actor->id,
            'decided_by_type' => $actor->merchant_id !== null ? 'merchant' : 'company',
            'decided_at' => now(),
            'decision_note' => $note ? mb_substr($note, 0, 500) : null,
        ])->save();
    }

    /** La remontée du livreur est traitée en même temps */
    private function closeReport(User $actor, OrderProposal $proposal, string $comment): void
    {
        if ($proposal->order_event_id === null) {
            return;
        }
        FieldReportReview::withoutGlobalScopes()->updateOrCreate(['order_event_id' => $proposal->order_event_id], [
            'company_id' => $proposal->company_id,
            'handled_by' => $actor->id,
            'comment' => mb_substr(($actor->merchant_id !== null ? 'Par le client : ' : '').$comment, 0, 500),
            'handled_at' => now(),
        ]);
    }

    private function line(User $actor, string $what, ?string $note): string
    {
        return $what.($actor->merchant_id !== null ? ' par le client' : '').($note ? " : {$note}" : '');
    }
}
