<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Courses en retard › « À décider » : le colis est chez un livreur ou au dépôt et sa date est passée.
 * Deux décisions : le renvoyer à le client (en le confiant tout de suite à un livreur, ou plus tard depuis
 * Retours clients) ou le reporter à une nouvelle date (en gardant le livreur qui l'a). Le colis sort alors de la liste.
 */
class PickedLateDecisions
{
    public function __construct(
        private readonly OrderDecisions $decisions,
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderService $orders,
        private readonly OrderJournal $journal,
    ) {}

    /** Renvoyer à le client ; sans livreur, la mission de livraison en cours est retirée et le retour attend */
    public function returnToMerchant(User $actor, Order $order, ?Courier $courier): void
    {
        $this->assertPickedLate($order);

        DB::transaction(function () use ($actor, $order, $courier) {
            $note = 'Ramassé, date de livraison dépassée : retour décidé par l\'agence';
            if ($this->decidable($order)) {
                $this->decisions->decide($actor, $order, ['decision' => OrderDecisions::RETURN, 'courier_id' => $courier?->id, 'note' => $note]);

                return;
            }

            $this->orders->requestReturn($actor, $order, $note);
            if ($courier) {
                $this->dispatcher->assign($actor, $order->fresh(), AssignmentType::Return, $courier);
            } elseif ($order->activeAssignment(AssignmentType::Delivery)) {
                $this->dispatcher->unassign($actor, $order->fresh(), AssignmentType::Delivery, 'Retour décidé par l\'agence');
            }
        });
    }

    /** Reporter à une nouvelle date ; le livreur qui a le colis le garde pour la livraison (si demandé) */
    public function reschedule(User $actor, Order $order, string $date, bool $keepCourier = true): void
    {
        $this->assertPickedLate($order);
        $day = CarbonImmutable::parse($date)->startOfDay();
        if ($day->lt(today())) {
            throw new BusinessRuleException('Choisissez une date à venir.', 'date');
        }

        DB::transaction(function () use ($actor, $order, $day, $keepCourier) {
            $holder = $keepCourier && $order->held_by_courier_id ? Courier::find($order->held_by_courier_id) : null;

            if ($this->decidable($order)) {
                $this->decisions->decide($actor, $order, ['decision' => OrderDecisions::REDELIVER, 'date' => $day->toDateString(), 'courier_id' => $holder?->id]);

                return;
            }

            $order->forceFill(['delivery_scheduled_date' => $day->toDateString()])->save();
            $this->journal->record($order, $actor, OrderEventType::Note, [
                'note' => 'Date de livraison dépassée : livraison reportée au '.$day->format('d/m/Y').' par l\'agence',
            ]);
            if ($holder && ! $order->activeAssignment(AssignmentType::Delivery)) {
                $this->dispatcher->assign($actor, $order->fresh(), AssignmentType::Delivery, $holder);
            }
        });
    }

    private function assertPickedLate(Order $order): void
    {
        if (! Order::query()->pickedLate()->whereKey($order->id)->exists()) {
            throw new BusinessRuleException("{$order->tracking_code} : plus à décider (déjà décidé, livré, ou date pas encore passée).", 'order_ids');
        }
    }

    /** Décision classique possible (échec, report, dépôt) : aucune mission en cours */
    private function decidable(Order $order): bool
    {
        return in_array($order->status, OrderDecisions::DECIDABLE, true)
            && ! $order->activeAssignment(AssignmentType::Delivery)
            && ! $order->activeAssignment(AssignmentType::Return);
    }
}
