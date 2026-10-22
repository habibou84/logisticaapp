<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicV1\UpdatePublicOrderRequest;
use App\Http\Requests\V1\StoreOrderRequest;
use App\Http\Resources\PublicV1\PublicOrderResource;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\ZoneResource;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\Product;
use App\Models\Zone;
use App\Services\Orders\DeliveryDay;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use App\Services\Pricing\PricingService;
use App\Services\Reports\OrderSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API publique d'un marchand (/api/public/v1), authentifiée par clé API.
 * Mêmes règles que l'application du marchand : la clé agit au nom d'un de ses comptes.
 */
class PublicApiController extends Controller
{
    private const RELATIONS = ['deliveryZone', 'pickupZone', 'items', 'lastIncidentReason', 'attachments'];

    public function zones(): AnonymousResourceCollection
    {
        return ZoneResource::collection(Zone::query()->active()->with('parent')->orderBy('name')->get());
    }

    public function hubs(): JsonResponse
    {
        return response()->json(['data' => Hub::query()->active()->orderBy('name')->get()
            ->map(fn (Hub $h) => ['id' => $h->id, 'name' => $h->name, 'zone_id' => $h->zone_id])]);
    }

    /**
     * Journée de livraison de l'entreprise : ouverte ou fermée (heure limite passée, fermée par l'agence),
     * et premier jour de livraison possible. L'application du marchand l'affiche avant d'envoyer une course.
     */
    public function deliveryDay(Request $request, DeliveryDay $day): JsonResponse
    {
        $status = $day->status($request->user()->company);

        return response()->json(['data' => [
            'closed' => $status['closed'],
            'reason' => $status['reason'],
            'message' => $status['message'],
            'cutoff_time' => $status['cutoff_time'],
            'today' => $status['today'],
            'next_day' => $status['next_day'],
            // Premier jour où une nouvelle course peut être livrée
            'earliest_delivery_date' => $status['closed'] ? $status['next_day'] : $status['today'],
        ]]);
    }

    public function quote(Request $request, PricingService $pricing): JsonResponse
    {
        return app(QuoteController::class)($request, $pricing);
    }

    public function orders(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(OrderStatus::class)],
            'merchant_reference' => ['nullable', 'string', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $this->query($request)
            ->with(self::RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', $request->input('status')))
            ->when($request->filled('merchant_reference'), fn ($q) => $q->where('merchant_reference', $request->string('merchant_reference')))
            ->when($request->filled('updated_since'), fn ($q) => $q->where('updated_at', '>=', $request->date('updated_since')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate($request->integer('per_page', 30));

        return PublicOrderResource::collection($orders);
    }

    /**
     * Applications partenaires reconnues (en-tête X-Client-App) : la course garde leur nom comme origine, pour
     * mesurer le volume qu'elles apportent.
     */
    public const CLIENT_APPS = ['jibiat-marchand' => 'ecommerce_app'];

    public function store(StoreOrderRequest $request, OrderService $service): JsonResponse
    {
        $user = $request->user();
        $source = self::CLIENT_APPS[strtolower((string) $request->header('X-Client-App'))] ?? 'api';
        $order = $service->create($user, Merchant::findOrFail($user->merchant_id), $request->validated(), $source);

        return PublicOrderResource::make($order->fresh()->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $trackingCode): PublicOrderResource
    {
        return PublicOrderResource::make($this->find($request, $trackingCode)->load([
            ...self::RELATIONS,
            'events' => fn ($q) => $q->where('visible_to_merchant', true)->with('incidentReason')->orderBy('id'),
        ]));
    }

    public function cancel(Request $request, string $trackingCode, OrderWorkflow $workflow): PublicOrderResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $order = $workflow->transition($request->user(), $this->find($request, $trackingCode), OrderStatus::Cancelled, [
            'cancel_reason' => $data['reason'] ?? null,
        ]);

        return PublicOrderResource::make($order->fresh()->load(self::RELATIONS));
    }

    /**
     * Modification par le marchand : tout avant le ramassage (le prix est recalculé), seulement la livraison
     * ensuite, rien une fois le colis en route ou livré (règles de OrderService::update).
     */
    public function update(UpdatePublicOrderRequest $request, string $trackingCode, OrderService $service): PublicOrderResource
    {
        $order = $this->find($request, $trackingCode);
        Gate::authorize('update', $order);
        $this->channel($request);

        return PublicOrderResource::make($service->update($request->user(), $order, $request->validated())->fresh()->load(self::RELATIONS));
    }

    /**
     * Suite d'une livraison échouée, choisie par le marchand : relivrer à une date, ou faire revenir le colis.
     */
    public function decision(Request $request, string $trackingCode, OrderWorkflow $workflow, OrderService $service): PublicOrderResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['redeliver', 'return'])],
            'date' => ['required_if:decision,redeliver', 'nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['decision' => 'décision', 'date' => 'date de livraison']);
        $order = $this->find($request, $trackingCode);
        if (! $order->needsDecision()) {
            throw new BusinessRuleException('Aucune décision n\'est attendue pour cette course.', 'decision');
        }
        $this->channel($request);

        if ($data['decision'] === 'redeliver') {
            $workflow->transition($request->user(), $order, OrderStatus::Rescheduled, [
                'rescheduled_to' => $data['date'], 'note' => $data['note'] ?? null,
            ]);
        } else {
            Gate::authorize('requestReturn', $order);
            $service->requestReturn($request->user(), $order, $data['note'] ?? null);
        }

        return PublicOrderResource::make($order->fresh()->load(self::RELATIONS));
    }

    /**
     * Retour du colis demandé par le marchand (colis déjà ramassé : avant, il annule la course).
     */
    public function returnRequest(Request $request, string $trackingCode, OrderService $service): PublicOrderResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $order = $this->find($request, $trackingCode);
        Gate::authorize('requestReturn', $order);
        $this->channel($request);

        $service->requestReturn($request->user(), $order, $data['note'] ?? null);

        return PublicOrderResource::make($order->fresh()->load(self::RELATIONS));
    }

    /**
     * Note du marchand pour l'agence et le livreur (« appeler avant », « portail bleu »…).
     */
    public function note(Request $request, string $trackingCode, OrderService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']], [], ['note' => 'note']);
        $order = $this->find($request, $trackingCode);
        Gate::authorize('addNote', $order);
        $this->channel($request);

        $service->addNote($request->user(), $order, $data['note']);

        return response()->json(['data' => ['tracking_code' => $order->tracking_code, 'note' => $data['note']]], 201);
    }

    public function summary(Request $request, OrderSummary $summary): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $summary->build(
            $this->query($request),
            $request->date('from') ?? today()->startOfMonth(),
            $request->date('to') ?? today(),
        )]);
    }

    public function products(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:200'], 'search' => ['nullable', 'string', 'max:100']]);

        $products = Product::query()
            ->where('merchant_id', $request->user()->merchant_id)
            ->where('is_active', true)
            ->when($request->filled('search'), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($request->string('search')).'%']))
            ->with('levels.location.hub')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return ProductResource::collection($products);
    }

    /**
     * Photo de preuve (ramassage, livraison, incident), servie par un lien signé de PublicOrderResource.
     */
    public function attachment(int $attachment): StreamedResponse
    {
        $model = OrderAttachment::query()->findOrFail($attachment);

        return Storage::disk($model->disk)->response($model->path);
    }

    /**
     * Application d'où vient l'action (« Jibiat Marchand », sinon « API ») : notée dans l'historique de la course.
     */
    private function channel(Request $request): void
    {
        Context::add('order_channel', strtolower((string) $request->header('X-Client-App')) === 'jibiat-marchand' ? 'Jibiat Marchand' : 'API');
    }

    private function query(Request $request): Builder
    {
        return Order::query()->where('merchant_id', $request->user()->merchant_id);
    }

    private function find(Request $request, string $trackingCode): Order
    {
        return $this->query($request)->where('tracking_code', mb_strtoupper($trackingCode))->firstOrFail();
    }
}
