<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\NotificationEvent;
use App\Enums\WhatsAppTemplate;
use App\Jobs\SendOutboundMessage;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\Push\WebPush;
use App\Support\PhoneNumber;

/**
 * Point d'entrée unique des messages WhatsApp, SMS et notifications push aux marchands :
 * enregistre le message dans le journal puis le confie à la file d'attente.
 */
class Messenger
{
    /**
     * Message au marchand, selon ses préférences.
     *
     * @param  list<string|int>  $params
     */
    public function toMerchant(Merchant $merchant, NotificationEvent $event, WhatsAppTemplate $template, array $params, ?Order $order = null): ?OutboundMessage
    {
        // Course envoyée par une application qui prévient elle-même le marchand (Jibiat Marchand) : pas de doublon
        if (! $merchant->wantsNotification($event) || $order?->merchant_alerts_muted) {
            return null;
        }

        return $this->merchantMessage($merchant, $event->value, $template, $params, ['order_id' => $order?->id]);
    }

    /**
     * Message au marchand : notification sur son téléphone quand il l'a activée dans
     * l'application (gratuit), sinon WhatsApp. Une notification qu'aucun appareil
     * n'a reçue repart par WhatsApp (voir fallbackToWhatsApp).
     *
     * @param  list<string|int>  $params
     * @param  array<string, mixed>  $context
     */
    public function merchantMessage(Merchant $merchant, string $event, WhatsAppTemplate $template, array $params, array $context = []): ?OutboundMessage
    {
        $context = [...$context, 'event' => $event, 'merchant_id' => $merchant->id];

        if (! $this->pushFirst($merchant)) {
            return $this->whatsapp($merchant->company_id, $merchant->messagingPhone(), 'merchant', $template, $params, $context);
        }

        $params = array_map(fn ($p) => self::cleanParameter((string) $p), $params);

        $message = OutboundMessage::create([
            ...$context,
            'company_id' => $merchant->company_id,
            'channel' => MessageChannel::Push,
            'to' => PhoneNumber::normalize($merchant->messagingPhone()) ?? '',
            'recipient_type' => 'merchant',
            'template_name' => $template->value,
            'payload' => $params,
            'body' => $template->render($params),
            'status' => MessageStatus::Queued,
        ]);

        SendOutboundMessage::dispatch($message)->afterCommit();

        return $message;
    }

    private function pushFirst(Merchant $merchant): bool
    {
        return WebPush::enabled()
            && (Company::find($merchant->company_id)?->merchant_push_first ?? false)
            && $merchant->pushSubscriptions()->exists();
    }

    /**
     * Contenu de la notification push d'un message au marchand.
     *
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public static function pushPayload(OutboundMessage $message): array
    {
        $template = WhatsAppTemplate::tryFrom((string) $message->template_name);

        // « Bonjour Boutique Awa, le colis… » → « Le colis… » : le titre suffit à situer, et
        // la notification ouvre déjà la bonne page (liens et formules de politesse retirés)
        $body = preg_replace('/^Bonjour [^,]*, /u', '', $message->body);
        $body = preg_replace('/[^.]*https?:\/\/\S+ \./u', '', $body);
        $body = trim(preg_replace('/\s*Merci( de votre confiance)?\.$/u', '', $body));

        return [
            'title' => $template?->notificationTitle() ?? 'Nouveau message',
            'body' => mb_strtoupper(mb_substr($body, 0, 1)).mb_substr($body, 1),
            'url' => match (true) {
                $message->order_id !== null => '/marchand/courses/'.$message->order_id,
                $message->event === NotificationEvent::PayoutPaid->value => '/marchand/paiements',
                str_starts_with((string) $message->event, 'report.') => '/marchand/statistiques',
                default => '/marchand',
            },
            'tag' => ($message->event ?? 'message').'-'.($message->order_id ?? $message->id),
        ];
    }

    /**
     * WhatsApp de repli quand la notification n'est arrivée sur aucun appareil du marchand.
     */
    public function fallbackToWhatsApp(OutboundMessage $message): ?OutboundMessage
    {
        $template = WhatsAppTemplate::tryFrom((string) $message->template_name);
        $merchant = Merchant::withoutGlobalScopes()->find($message->merchant_id);

        if ($message->channel !== MessageChannel::Push || $template === null || $merchant === null || $message->fallback()->exists()) {
            return null;
        }

        return $this->whatsapp($message->company_id, $merchant->messagingPhone(), 'merchant', $template, $message->payload ?? [], [
            'event' => $message->event,
            'order_id' => $message->order_id,
            'merchant_id' => $merchant->id,
            'fallback_for_id' => $message->id,
        ]);
    }

    /**
     * Message au destinataire d'une course, si l'entreprise l'a activé.
     *
     * @param  list<string|int>  $params
     */
    public function toRecipient(Order $order, string $event, WhatsAppTemplate $template, array $params): ?OutboundMessage
    {
        $company = Company::find($order->company_id);

        if (! $company?->notify_recipients) {
            return null;
        }

        return $this->whatsapp($order->company_id, $order->recipient_phone, 'recipient', $template, $params, [
            'event' => $event,
            'merchant_id' => $order->merchant_id,
            'order_id' => $order->id,
        ]);
    }

    /**
     * @param  list<string|int>  $params
     * @param  array<string, mixed>  $context
     */
    public function whatsapp(int $companyId, ?string $to, string $recipientType, WhatsAppTemplate $template, array $params, array $context = []): ?OutboundMessage
    {
        $to = PhoneNumber::normalize($to);
        if ($to === null) {
            return null;
        }

        $params = array_map(fn ($p) => self::cleanParameter((string) $p), $params);

        $message = OutboundMessage::create([
            ...$context,
            'company_id' => $companyId,
            'channel' => MessageChannel::WhatsApp,
            'whatsapp_account_id' => WhatsAppAccount::forCompanyId($companyId)?->id,
            'to' => $to,
            'recipient_type' => $recipientType,
            'template_name' => $template->value,
            'payload' => $params,
            'body' => $template->render($params),
            'status' => MessageStatus::Queued,
        ]);

        SendOutboundMessage::dispatch($message)->afterCommit();

        return $message;
    }

    /**
     * Réponse dans une conversation WhatsApp ouverte par l'expéditeur (pas de modèle,
     * pas de SMS de repli), avec des boutons de réponse rapide facultatifs.
     *
     * @param  array<string, string>  $buttons  identifiant => libellé (3 au plus)
     * @param  array<string, mixed>  $context
     */
    public function reply(int $companyId, string $to, string $text, array $buttons = [], array $context = []): OutboundMessage
    {
        $message = OutboundMessage::create([
            ...$context,
            'company_id' => $companyId,
            'channel' => MessageChannel::WhatsApp,
            'whatsapp_account_id' => WhatsAppAccount::forCompanyId($companyId)?->id,
            'to' => PhoneNumber::normalize($to) ?? $to,
            'recipient_type' => $context['recipient_type'] ?? 'merchant',
            'event' => 'conversation',
            'template_name' => null,
            'payload' => $buttons === [] ? null : ['buttons' => $buttons],
            'body' => $text,
            'status' => MessageStatus::Queued,
        ]);

        SendOutboundMessage::dispatch($message)->afterCommit();

        return $message;
    }

    /**
     * Message de test : modèle « hello_world » fourni par Meta à tout nouveau numéro.
     */
    public function test(int $companyId, string $to): OutboundMessage
    {
        $message = OutboundMessage::create([
            'company_id' => $companyId,
            'channel' => MessageChannel::WhatsApp,
            'whatsapp_account_id' => WhatsAppAccount::forCompanyId($companyId)?->id,
            'to' => PhoneNumber::normalize($to),
            'recipient_type' => 'test',
            'template_name' => 'hello_world',
            'payload' => [],
            'body' => 'Message de test (modèle hello_world de Meta)',
            'status' => MessageStatus::Queued,
        ]);

        SendOutboundMessage::dispatch($message)->afterCommit();

        return $message;
    }

    /**
     * SMS de repli quand un message WhatsApp n'a pas été remis (une seule fois par message).
     */
    public function fallbackToSms(OutboundMessage $message): ?OutboundMessage
    {
        if ($message->channel !== MessageChannel::WhatsApp
            || $message->recipient_type === 'test'
            || $message->event === 'conversation'
            || config('messaging.sms.driver') === 'none'
            || ! Company::find($message->company_id)?->sms_fallback
            || $message->fallback()->exists()) {
            return null;
        }

        $sms = OutboundMessage::create([
            'company_id' => $message->company_id,
            'channel' => MessageChannel::Sms,
            'to' => $message->to,
            'recipient_type' => $message->recipient_type,
            'event' => $message->event,
            'template_name' => $message->template_name,
            'body' => $message->body,
            'order_id' => $message->order_id,
            'merchant_id' => $message->merchant_id,
            'fallback_for_id' => $message->id,
            'status' => MessageStatus::Queued,
        ]);

        SendOutboundMessage::dispatch($sms)->afterCommit();

        return $sms;
    }

    /**
     * Relance manuelle d'un message en échec : nouvel envoi, même contenu.
     */
    public function retry(OutboundMessage $message): OutboundMessage
    {
        $copy = $message->replicate(['provider_message_id', 'error', 'attempts', 'sent_at', 'delivered_at', 'read_at', 'failed_at']);
        $copy->status = MessageStatus::Queued;
        $copy->fallback_for_id = null;
        $copy->save();

        SendOutboundMessage::dispatch($copy)->afterCommit();

        return $copy;
    }

    /**
     * Meta refuse les paramètres contenant des retours à la ligne, des tabulations
     * ou plus de 4 espaces consécutifs, ainsi que les paramètres vides.
     */
    public static function cleanParameter(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? '-' : mb_substr($value, 0, 300);
    }
}
