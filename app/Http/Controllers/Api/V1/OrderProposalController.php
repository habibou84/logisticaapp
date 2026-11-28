<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderProposals;
use Illuminate\Http\Request;

/**
 * Report ou retour signalé par le livreur, à valider : l'équipe (dispatch) ou le client de la course le valide
 * (il prend effet) ou le refuse (la course reste en échec, l'équipe décide de la suite).
 */
class OrderProposalController extends Controller
{
    public function __construct(private readonly OrderProposals $proposals) {}

    public function approve(Request $request, Order $order): OrderResource
    {
        abort_unless(OrderProposals::canDecide($request->user(), $order), 403, 'Vous ne pouvez pas valider cette course.');
        $data = $request->validate([
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['date' => 'date de livraison']);

        $order = $this->proposals->approve($request->user(), $order, $data['date'] ?? null, $data['note'] ?? null);

        return OrderResource::make($order->load(OrderController::loadDetailRelations($request)));
    }

    public function reject(Request $request, Order $order): OrderResource
    {
        abort_unless(OrderProposals::canDecide($request->user(), $order), 403, 'Vous ne pouvez pas valider cette course.');
        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ], ['note.required' => 'Dites pourquoi : le livreur et l\'équipe le verront.'], ['note' => 'motif du refus']);

        $order = $this->proposals->reject($request->user(), $order, $data['note']);

        return OrderResource::make($order->load(OrderController::loadDetailRelations($request)));
    }
}
