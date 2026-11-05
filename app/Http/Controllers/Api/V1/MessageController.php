<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OutboundMessageResource;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Services\Messaging\Messenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Journal des messages WhatsApp et SMS envoyés par l'entreprise.
 */
class MessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permission::OrdersDispatch->value);

        $request->validate([
            'status' => ['nullable', Rule::enum(MessageStatus::class)],
            'channel' => ['nullable', 'in:whatsapp,sms,push'],
            'search' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $messages = OutboundMessage::query()
            ->with(['order:id,tracking_code', 'merchant:id,business_name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.preg_replace('/\s+/', '', $request->string('search')).'%';
                $q->where(fn ($q) => $q->where('to', 'like', $term)
                    ->orWhereHas('order', fn ($o) => $o->where('tracking_code', 'like', mb_strtoupper($term))));
            })
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 30), 1), 500));

        return OutboundMessageResource::collection($messages);
    }

    /**
     * Messages liés à une course (fiche colis, personnel uniquement).
     */
    public function forOrder(Request $request, Order $order): AnonymousResourceCollection
    {
        Gate::authorize('view', $order);
        abort_if($request->user()->merchant_id !== null || $request->user()->isCourier(), 403);

        return OutboundMessageResource::collection(
            OutboundMessage::where('order_id', $order->id)->latest('id')->get()
        );
    }

    public function retry(OutboundMessage $message, Messenger $messenger): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);
        abort_unless($message->status === MessageStatus::Failed, 422, 'Seuls les messages en échec peuvent être renvoyés.');

        $copy = $messenger->retry($message);

        return response()->json(['data' => new OutboundMessageResource($copy->fresh())], 201);
    }
}
