<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Push\WebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nouvel essai d'une notification push restée sans réponse du service push (panne passagère).
 */
class RetryWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Délais entre les essais (secondes) */
    public const BACKOFF = [30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public int $subscriptionId, public array $payload)
    {
        $this->onQueue('messages');
    }

    public function handle(WebPush $push): void
    {
        $subscription = PushSubscription::find($this->subscriptionId);

        if ($subscription === null || ! WebPush::enabled() || $push->attempt($subscription, $this->payload) !== 'error') {
            return;
        }

        if ($this->attempts() < count(self::BACKOFF)) {
            $this->release(self::BACKOFF[$this->attempts()]);
        }
    }
}
