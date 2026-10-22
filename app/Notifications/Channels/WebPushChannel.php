<?php

namespace App\Notifications\Channels;

use App\Jobs\RetryWebPush;
use App\Models\PushSubscription;
use App\Services\Push\WebPush;
use App\Support\Appearance;
use Illuminate\Notifications\Notification;

/**
 * Canal de notification « push » : envoie toWebPush() à chaque appareil abonné de l'utilisateur.
 */
class WebPushChannel
{
    public function __construct(private readonly WebPush $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! WebPush::enabled() || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $payload = $notification->toWebPush($notifiable);
        // Icône de l'application de l'entreprise (Paramètres › Apparence)
        $app = str_starts_with((string) ($payload['url'] ?? ''), '/marchand') ? 'marchand' : 'livreur';
        $payload['icon'] ??= Appearance::iconUrl($notifiable->company ?? null, $app, '192');

        // Échec passager du service push (panne, délai dépassé) : nouvel essai un peu plus tard,
        // sans renvoyer la cloche ni les autres canaux de la notification
        PushSubscription::where('user_id', $notifiable->getKey())->get()
            ->each(function (PushSubscription $subscription) use ($payload) {
                if ($this->push->attempt($subscription, $payload) === 'error') {
                    RetryWebPush::dispatch($subscription->id, $payload)->delay(now()->addSeconds(RetryWebPush::BACKOFF[0]));
                }
            });
    }
}
