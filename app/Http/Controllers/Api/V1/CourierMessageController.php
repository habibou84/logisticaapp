<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierMessage;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Couriers\CourierMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Messages du dispatch aux livreurs (côté agence) et leur lecture (côté livreur).
 */
class CourierMessageController extends Controller
{
    public function __construct(private readonly CourierMessenger $messenger) {}

    public function index(Order $order): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        return response()->json(['data' => CourierMessage::where('order_id', $order->id)->with('sender')->orderBy('id')->get()
            ->map(fn (CourierMessage $m) => $m->present())]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:500'],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $order->company_id)],
            'reply_to_event_id' => ['nullable', 'integer', Rule::exists('order_events', 'id')->where('order_id', $order->id)],
            'mark_handled' => ['sometimes', 'boolean'],
        ], [], ['body' => 'consigne']);

        $replyTo = isset($data['reply_to_event_id']) ? OrderEvent::find($data['reply_to_event_id']) : null;
        // Destinataire : le livreur précisé, sinon l'auteur de la remontée, sinon le livreur en mission
        $courier = isset($data['courier_id']) ? Courier::findOrFail($data['courier_id'])
            : ($replyTo?->actor_id ? Courier::where('user_id', $replyTo->actor_id)->first() : null)
            ?? $order->assignments()->active()->latest('id')->first()?->courier;

        abort_if($courier === null, 422, 'Aucun livreur en mission sur cette course.');

        $message = $this->messenger->send($request->user(), $order, $courier, $data['body'], $replyTo, (bool) ($data['mark_handled'] ?? false));

        return response()->json(['data' => $message->load('sender')->present()], 201);
    }

    /**
     * Messages reçus par le livreur connecté (pour une course ou les non lus), marqués comme lus.
     */
    public function mine(Request $request): JsonResponse
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403);
        $request->validate(['order_id' => ['nullable', 'integer'], 'mark_read' => ['sometimes', 'boolean']]);

        $messages = CourierMessage::where('courier_id', $courier->id)
            ->when($request->filled('order_id'), fn ($q) => $q->where('order_id', $request->integer('order_id')),
                fn ($q) => $q->where('created_at', '>=', now()->subDays(2)))
            ->with(['sender', 'order'])
            ->orderBy('id')
            ->get();

        if ($request->filled('order_id') || $request->boolean('mark_read')) {
            CourierMessage::whereIn('id', $messages->whereNull('read_at')->modelKeys())->update(['read_at' => now()]);
        }

        return response()->json(['data' => $messages->map(fn (CourierMessage $m) => [
            ...$m->present(),
            'tracking_code' => $m->order?->tracking_code,
            'recipient_name' => $m->order?->recipient_name,
        ])]);
    }

    public function acknowledge(Request $request, CourierMessage $message): JsonResponse
    {
        abort_unless($message->courier_id === $request->user()->courier?->id, 404);

        $message->forceFill(['read_at' => $message->read_at ?? now(), 'acknowledged_at' => $message->acknowledged_at ?? now()])->save();

        return response()->json(['data' => $message->load('sender')->present()]);
    }
}
