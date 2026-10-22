<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\WebhookDisabled;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Throwable;

/**
 * Envoie un webhook signé : en-tête X-Webhook-Signature = sha256=HMAC-SHA256(secret, "<timestamp>.<corps>"), calculé
 * par le paquet commun habibou84/socle (vérifié avec le même code par Jibiat Marchand).
 * En cas d'échec (pas de réponse 2xx), nouvelle tentative après 1 min, 5 min, 30 min, 2 h puis 6 h.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    // Délais avant les tentatives 2 à 6
    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    // Envois définitivement échoués d'affilée avant de couper l'adresse
    public const DISABLE_AFTER = 20;

    public int $tries = 1;

    public function __construct(public int $deliveryId, public bool $retry = true) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->with(['subscription' => fn ($q) => $q->withoutGlobalScopes()])->find($this->deliveryId);

        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }

        $subscription = $delivery->subscription;
        $delivery->attempts++;

        if (! $subscription?->is_active) {
            $this->fail($delivery, 'Adresse désactivée.', final: true);

            return;
        }

        if ($reason = UrlGuard::blockedReason($subscription->url)) {
            $this->fail($delivery, $reason, final: true);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        try {
            $response = Http::timeout(config('services.webhooks.timeout', 10))
                ->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => config('app.name').' Webhooks/1.0',
                    'X-Webhook-Id' => $delivery->event_id,
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => WebhookSignature::sign($subscription->secret, (int) $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->url);
        } catch (Throwable $e) {
            $this->fail($delivery, Str::limit($e->getMessage(), 250));

            return;
        }

        $delivery->response_code = $response->status();
        $delivery->response_body = Str::limit($response->body(), 1000);

        if ($response->successful()) {
            $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'error' => null, 'next_retry_at' => null])->save();
            $subscription->forceFill(['consecutive_failures' => 0])->saveQuietly();

            return;
        }

        $this->fail($delivery, "Réponse HTTP {$response->status()}");
    }

    private function fail(WebhookDelivery $delivery, string $error, bool $final = false): void
    {
        $delay = $this->retry && ! $final ? (self::BACKOFF[$delivery->attempts - 1] ?? null) : null;

        $delivery->forceFill([
            'status' => $delay ? 'pending' : 'failed',
            'error' => $error,
            'next_retry_at' => $delay ? now()->addSeconds($delay) : null,
        ])->save();

        if ($delay) {
            self::dispatch($delivery->id)->delay($delay);
        } elseif ($this->retry && ($subscription = $delivery->subscription)) {
            $subscription->increment('consecutive_failures');

            // Adresse morte : coupée (les envois s'empilaient en échec), le marchand est prévenu
            if ($subscription->is_active && $subscription->consecutive_failures >= self::DISABLE_AFTER) {
                $subscription->forceFill(['is_active' => false])->saveQuietly();
                Notification::send(
                    User::where('merchant_id', $subscription->merchant_id)->where('status', 'active')->get(),
                    new WebhookDisabled($subscription),
                );
            }
        }
    }
}
