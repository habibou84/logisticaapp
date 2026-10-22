<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookEvent;
use App\Http\Controllers\Concerns\ResolvesIntegrationMerchant;
use App\Http\Controllers\Controller;
use App\Jobs\SendWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\UrlGuard;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Adresses webhook d'un marchand : création (secret montré une fois), test, journal des envois et renvoi.
 */
class WebhookController extends Controller
{
    use ResolvesIntegrationMerchant;

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->integrationMerchant($request, required: false);

        $subscriptions = WebhookSubscription::query()
            ->with('merchant')
            ->withCount(['deliveries as failed_count' => fn ($q) => $q->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))])
            ->when($merchant, fn ($q) => $q->where('merchant_id', $merchant->id))
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $subscriptions->map(fn (WebhookSubscription $s) => $this->present($s)),
            'events' => collect(WebhookEvent::cases())->map(fn (WebhookEvent $e) => ['value' => $e->value, 'label' => $e->label()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->integrationMerchant($request);
        $data = $request->validate($this->rules());
        UrlGuard::validate($data['url']);

        $secret = 'whsec_'.Str::random(40);
        $subscription = WebhookSubscription::create([
            ...$data,
            'company_id' => $merchant->company_id,
            'merchant_id' => $merchant->id,
            'secret' => $secret,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($subscription->load('merchant')), 'secret' => $secret], 201);
    }

    public function update(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $this->ensureOwnsIntegration($request, $webhook->merchant_id);
        $data = $request->validate($this->rules(partial: true));

        if (isset($data['url'])) {
            UrlGuard::validate($data['url']);
        }

        // Réactivation : le compteur d'échecs repart de zéro
        if (($data['is_active'] ?? false) && ! $webhook->is_active) {
            $webhook->consecutive_failures = 0;
        }

        $webhook->fill($data)->save();

        return response()->json(['data' => $this->present($webhook->load('merchant'))]);
    }

    public function destroy(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $this->ensureOwnsIntegration($request, $webhook->merchant_id);
        $webhook->delete();

        return response()->json(null, 204);
    }

    /**
     * Nouveau secret de signature (l'ancien cesse aussitôt d'être utilisé).
     */
    public function rotateSecret(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $this->ensureOwnsIntegration($request, $webhook->merchant_id);

        $secret = 'whsec_'.Str::random(40);
        $webhook->forceFill(['secret' => $secret])->save();

        return response()->json(['data' => $this->present($webhook->load('merchant')), 'secret' => $secret]);
    }

    /**
     * Envoie tout de suite un événement « ping » et renvoie le résultat.
     */
    public function test(Request $request, WebhookSubscription $webhook, WebhookDispatcher $dispatcher): JsonResponse
    {
        $this->ensureOwnsIntegration($request, $webhook->merchant_id);

        $delivery = $dispatcher->send($webhook, 'ping', ['message' => 'Test de votre adresse webhook.'], now: true);

        return response()->json(['data' => $this->presentDelivery($delivery->fresh())]);
    }

    public function deliveries(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $this->ensureOwnsIntegration($request, $webhook->merchant_id);

        $deliveries = $webhook->deliveries()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 30), 100));

        return response()->json([
            'data' => collect($deliveries->items())->map(fn (WebhookDelivery $d) => $this->presentDelivery($d)),
            'meta' => ['current_page' => $deliveries->currentPage(), 'last_page' => $deliveries->lastPage(), 'total' => $deliveries->total()],
        ]);
    }

    public function redeliver(Request $request, WebhookDelivery $delivery): JsonResponse
    {
        $delivery->load('subscription');
        $this->ensureOwnsIntegration($request, $delivery->subscription->merchant_id);

        $delivery->forceFill(['status' => 'pending', 'delivered_at' => null, 'next_retry_at' => null])->save();
        (new SendWebhook($delivery->id, retry: false))->handle();

        return response()->json(['data' => $this->presentDelivery($delivery->fresh())]);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'url' => [$required, 'url:https,http', 'max:500'],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function present(WebhookSubscription $s): array
    {
        return [
            'id' => $s->id,
            'merchant_id' => $s->merchant_id,
            'merchant_name' => $s->merchant?->business_name,
            'url' => $s->url,
            'events' => $s->events,
            'description' => $s->description,
            'is_active' => $s->is_active,
            'consecutive_failures' => $s->consecutive_failures,
            'failed_last_week' => $s->failed_count ?? 0,
            'created_at' => $s->created_at,
        ];
    }

    private function presentDelivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id,
            'event_id' => $d->event_id,
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_code' => $d->response_code,
            'response_body' => $d->response_body,
            'error' => $d->error,
            'next_retry_at' => $d->next_retry_at,
            'delivered_at' => $d->delivered_at,
            'created_at' => $d->created_at,
            'payload' => $d->payload,
        ];
    }
}
