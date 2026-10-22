<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppTemplate;
use App\Models\Merchant;
use App\Models\OutboundMessage;
use App\Models\PushSubscription;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Services\Messaging\Gateways\WhatsAppGateway;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\Messenger;
use App\Services\Push\WebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Envoie un message du journal. Les erreurs passagères sont retentées ;
 * un échec définitif d'un message WhatsApp déclenche le SMS de repli,
 * une notification push reçue par aucun appareil déclenche le WhatsApp de repli.
 */
class SendOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** Essais d'une notification push avant le WhatsApp de repli */
    public const PUSH_TRIES = 2;

    public function __construct(public OutboundMessage $message)
    {
        $this->tries = config('messaging.tries');
        $this->onQueue('messages');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('messaging.backoff');
    }

    public function handle(WhatsAppGateway $whatsapp, SmsGateway $sms, Messenger $messenger, WebPush $push): void
    {
        $message = $this->message->fresh();

        if ($message === null || $message->status !== MessageStatus::Queued) {
            return;
        }

        $message->increment('attempts');

        if ($message->channel === MessageChannel::Push) {
            $this->sendPush($message, $push, $messenger);

            return;
        }

        try {
            $account = $message->whatsapp_account_id ? WhatsAppAccount::forCompany($message->company_id)->find($message->whatsapp_account_id) : null;

            $providerId = match (true) {
                $message->channel === MessageChannel::Sms => $sms->send($message->to, $message->body),
                // Réponse dans une conversation ouverte par l'expéditeur : texte libre et boutons
                $message->template_name === null => $whatsapp->sendReply($account, $message->to, $message->body, $message->payload['buttons'] ?? []),
                default => $whatsapp->sendTemplate(
                    $account,
                    $message->to,
                    $message->template_name,
                    $message->template_name === 'hello_world' ? 'en_US' : WhatsAppTemplate::LANGUAGE,
                    $message->payload ?? [],
                ),
            };
        } catch (MessagingException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $message->update(['error' => $e->getMessage()]);
                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);

                return;
            }

            $this->markFailed($message, $e->getMessage(), $messenger);

            return;
        }

        $message->provider_message_id = $providerId ?: null;
        $message->error = null;
        $message->advanceTo(MessageStatus::Sent);
    }

    public function failed(Throwable $e): void
    {
        $message = $this->message->fresh();

        if ($message && $message->status === MessageStatus::Queued) {
            $this->markFailed($message, $e->getMessage(), app(Messenger::class));
        }
    }

    /**
     * Notification sur les appareils du marchand ; si aucun ne l'a reçue, WhatsApp prend le relais.
     */
    private function sendPush(OutboundMessage $message, WebPush $push, Messenger $messenger): void
    {
        $merchant = Merchant::withoutGlobalScopes()->find($message->merchant_id);
        $payload = Messenger::pushPayload($message);

        // Un téléphone partagé par deux comptes du marchand ne reçoit la notification qu'une fois
        $results = WebPush::enabled() && $merchant
            ? $merchant->pushSubscriptions()->get()->unique('endpoint_hash')
                ->map(fn (PushSubscription $s) => $push->attempt($s, $payload, 86400, 'normal'))
            : collect();

        if ($results->contains('ok')) {
            $message->error = null;
            $message->advanceTo(MessageStatus::Sent);

            return;
        }

        // Service push momentanément indisponible : un nouvel essai avant de passer par WhatsApp
        if ($results->contains('error') && $this->attempts() < min(self::PUSH_TRIES, $this->tries)) {
            $message->update(['error' => 'Notification pas encore reçue : nouvel essai.']);
            $this->release($this->backoff()[$this->attempts() - 1] ?? 60);

            return;
        }

        $message->advanceTo(MessageStatus::Failed, 'Notification reçue par aucun appareil : envoyée par WhatsApp.');
        $messenger->fallbackToWhatsApp($message);
    }

    private function markFailed(OutboundMessage $message, string $error, Messenger $messenger): void
    {
        $message->advanceTo(MessageStatus::Failed, $error);
        $message->channel === MessageChannel::Push ? $messenger->fallbackToWhatsApp($message) : $messenger->fallbackToSms($message);
    }
}
