<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FieldEventResource;
use App\Models\FieldEvent;
use App\Models\FieldEventFile;
use App\Models\Order;
use App\Services\FieldEvents\FieldEvents;
use App\Services\FieldEvents\FieldEventSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Événements terrain côté livreur : ce qu'il peut signaler, ses signalements, l'échange avec l'agence,
 * et « C'est réglé, je reprends ».
 */
class CourierFieldEventController extends Controller
{
    public function __construct(
        private readonly FieldEvents $events,
        private readonly FieldEventSettings $settings,
    ) {}

    /**
     * Réglages utiles à la fiche de signalement, courses en main et signalements en cours.
     */
    public function setup(Request $request): JsonResponse
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403);
        $settings = $this->settings->for($courier->company);

        $orders = Order::whereIn('id', $this->events->activeOrderIds($courier))->with(['merchant', 'deliveryZone'])->orderBy('id')->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'tracking_code' => $o->tracking_code,
                'label' => $o->status->isBeforePickup()
                    ? 'Ramassage · '.($o->merchant?->business_name ?? '')
                    : 'Livraison · '.($o->recipient_name ?: $o->recipient_phone).($o->deliveryZone ? " ({$o->deliveryZone->name})" : ''),
            ])->values();

        return response()->json(['data' => [
            'enabled' => $settings['enabled'],
            'types' => $this->settings->types($courier->company),
            'voice_notes' => $settings['voice_notes'],
            'voice_max_seconds' => $settings['voice_max_seconds'],
            'courier_cost' => $settings['courier_cost'],
            'orders' => $orders,
            'open' => FieldEventResource::collection(FieldEvent::where('courier_id', $courier->id)->unresolved()->latest('id')->get()),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403);

        return response()->json(['data' => FieldEventResource::collection(
            FieldEvent::where('courier_id', $courier->id)->where('created_at', '>=', now()->subDays(7))->latest('id')->get()
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'max:40'],
            'capacity' => ['required', Rule::in(FieldEvent::CAPACITIES)],
            'blocked_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'cost' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer'],
            'all_orders' => ['sometimes', 'boolean'],
            'occurred_at' => ['nullable', 'string', 'max:40'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['image', 'max:8192'],
            'voice' => ['nullable', 'file', 'max:4096', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/aac,audio/wav,audio/x-m4a,video/webm'],
            'voice_duration' => ['nullable', 'integer', 'min:0', 'max:600'],
        ], [], ['type' => 'type d\'événement', 'photos.*' => 'photo', 'voice' => 'note vocale', 'cost' => 'coût']);

        // Aucune course cochée : order_ids vide envoyé (« all_orders » faux)
        if (! $request->boolean('all_orders', ! $request->has('order_ids'))) {
            $data['order_ids'] = $data['order_ids'] ?? [];
        } else {
            unset($data['order_ids']);
        }

        $event = $this->events->report($request->user(), $data, $request->file('photos', []), $request->file('voice'));

        return response()->json(['data' => FieldEventResource::make($event->fresh()->load(FieldEventResource::RELATIONS))], 201);
    }

    public function show(Request $request, FieldEvent $event): FieldEventResource
    {
        $this->ensureMine($request, $event);
        $event->messages()->where('from_courier', false)->whereNull('read_at')->update(['read_at' => now()]);

        return FieldEventResource::make($event->load(FieldEventResource::RELATIONS));
    }

    public function message(Request $request, FieldEvent $event): FieldEventResource
    {
        $this->ensureMine($request, $event);
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']], [], ['body' => 'message']);
        $this->events->message($request->user(), $event, $data['body']);

        return FieldEventResource::make($event->fresh()->load(FieldEventResource::RELATIONS));
    }

    public function resume(Request $request, FieldEvent $event): FieldEventResource
    {
        $this->ensureMine($request, $event);
        $this->events->resume($request->user(), $event);

        return FieldEventResource::make($event->fresh()->load(FieldEventResource::RELATIONS));
    }

    public function file(Request $request, FieldEvent $event, FieldEventFile $file): StreamedResponse
    {
        $this->ensureMine($request, $event);
        abort_unless($file->field_event_id === $event->id, 404);

        return Storage::disk($file->disk)->response($file->path);
    }

    private function ensureMine(Request $request, FieldEvent $event): void
    {
        abort_unless($event->courier_id === $request->user()->courier?->id, 404);
    }
}
