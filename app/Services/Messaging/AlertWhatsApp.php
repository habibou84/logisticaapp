<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Jobs\SendOutboundMessage;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Models\WhatsAppQrSession;
use App\Notifications\AlertWhatsAppDisconnected;
use App\Services\Messaging\Gateways\WahaClient;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * WhatsApp des alertes : numéro WhatsApp ordinaire relié en scannant un QR code, qui envoie
 * seulement les alertes de la direction (zones, clients, livreurs). Envois espacés et plafonnés,
 * déconnexion signalée aux administrateurs. Sans connexion, l'appelant garde son moyen habituel.
 */
class AlertWhatsApp
{
    public function __construct(private readonly WahaClient $waha) {}

    /**
     * État à afficher dans les réglages des alertes, relu auprès du service.
     *
     * @return array{available: bool, status: string, phone: ?string, name: ?string, connected_at: mixed, qr: ?string, error: ?string}
     */
    public function state(int $companyId): array
    {
        $session = WhatsAppQrSession::forCompanyId($companyId);
        $qr = null;

        if (WahaClient::available()) {
            try {
                $session = $this->refresh($companyId);
                $qr = $session->status === 'scanning' ? $this->waha->qr() : null;
            } catch (MessagingException $e) {
                $session->last_error = $e->getMessage();
            }
        }

        return [
            'available' => WahaClient::available(),
            'status' => $session->status,
            'phone' => $session->phone,
            'name' => $session->name,
            'connected_at' => $session->connected_at,
            'qr' => $qr,
            'error' => $session->last_error,
        ];
    }

    /**
     * Lance la connexion : la session démarre et affiche un QR code à scanner avec le téléphone.
     *
     * @throws MessagingException
     */
    public function connect(int $companyId): void
    {
        $this->waha->start();
        $this->refresh($companyId);
    }

    /**
     * Délie le numéro : les alertes repartent par le moyen habituel.
     *
     * @throws MessagingException
     */
    public function disconnect(int $companyId): void
    {
        $this->waha->logout();
        WhatsAppQrSession::forCompanyId($companyId)->fill([
            'status' => 'disconnected', 'phone' => null, 'name' => null, 'connected_at' => null,
            'disconnected_at' => null, 'warned_at' => null, 'last_error' => null, 'checked_at' => now(),
        ])->save();
    }

    /**
     * Relit l'état de la session auprès du service et l'enregistre.
     *
     * @throws MessagingException
     */
    public function refresh(int $companyId): WhatsAppQrSession
    {
        $session = WhatsAppQrSession::forCompanyId($companyId);
        $info = $this->waha->info();

        $wasConnected = $session->isConnected();
        $status = match ($info['status']) {
            'WORKING' => 'connected',
            // Redémarrage du service : la session reprend d'elle-même, sans nouveau scan
            'STARTING' => $wasConnected ? 'connected' : 'scanning',
            'SCAN_QR_CODE' => 'scanning',
            default => 'disconnected',
        };
        $session->fill(['status' => $status, 'checked_at' => now(), 'last_error' => null]);

        if ($status === 'connected') {
            $session->fill([
                'phone' => $info['phone'] ?? $session->phone,
                'name' => $info['name'] ?? $session->name,
                'connected_at' => $wasConnected ? $session->connected_at : now(),
                'disconnected_at' => null,
                'warned_at' => null,
            ]);
        } elseif ($wasConnected) {
            // Session perdue (téléphone délié, WhatsApp mis à jour…) : l'heure sert à prévenir les administrateurs
            $session->disconnected_at = now();
        }

        $session->save();

        return $session;
    }

    public function isConnected(int $companyId): bool
    {
        return WahaClient::available() && WhatsAppQrSession::forCompanyId($companyId)->isConnected();
    }

    /**
     * Confie un message à envoyer par le WhatsApp des alertes, à chaque numéro donné.
     * Messages espacés de quelques secondes et plafonnés à l'heure. Renvoie null si le
     * numéro n'est pas relié : l'appelant passe alors par son moyen habituel.
     *
     * @param  iterable<string|null>  $phones
     * @return list<OutboundMessage>|null
     */
    public function send(int $companyId, iterable $phones, string $text, string $event = 'stats.alert'): ?array
    {
        if (! $this->isConnected($companyId)) {
            return null;
        }

        [$min, $max] = config('messaging.qr.spacing', [4, 12]);
        $messages = [];

        foreach (collect($phones)->map(fn ($p) => PhoneNumber::normalize($p))->filter()->unique() as $to) {
            $hour = 'whatsapp-qr:sent:'.$companyId.':'.now()->format('YmdH');
            $sent = (int) Cache::get($hour, 0);
            $over = $sent >= (int) config('messaging.qr.max_per_hour', 30);

            $message = OutboundMessage::create([
                'company_id' => $companyId,
                'channel' => MessageChannel::WhatsApp,
                'via' => 'qr',
                'to' => $to,
                'recipient_type' => 'staff',
                'event' => $event,
                'body' => $text,
                'status' => $over ? MessageStatus::Failed : MessageStatus::Queued,
                'error' => $over ? 'Plafond horaire du WhatsApp des alertes atteint : message non envoyé.' : null,
                'failed_at' => $over ? now() : null,
            ]);
            $messages[] = $message;

            if ($over) {
                continue;
            }

            // Un message après l'autre, à quelques secondes d'intervalle (au hasard), même entre deux lots
            $slot = 'whatsapp-qr:next:'.$companyId;
            $at = max(now()->getTimestamp(), (int) Cache::get($slot, 0));
            Cache::put($slot, $at + random_int($min, $max), now()->addHour());
            Cache::put($hour, $sent + 1, now()->addHours(2));

            SendOutboundMessage::dispatch($message)->delay(max(0, $at - now()->getTimestamp()))->afterCommit();
        }

        return $messages;
    }

    /**
     * Message d'essai au numéro donné (bouton « Envoyer un essai »).
     *
     * @throws MessagingException
     */
    public function test(int $companyId, ?string $phone): OutboundMessage
    {
        if (! $this->isConnected($companyId)) {
            throw new MessagingException('Reliez d\'abord le WhatsApp des alertes en scannant le QR code.');
        }
        if (PhoneNumber::normalize($phone) === null) {
            throw new MessagingException('Ajoutez votre numéro de téléphone à votre compte pour recevoir l\'essai.');
        }

        return $this->send($companyId, [$phone], 'Essai : le WhatsApp des alertes est bien relié. Vous recevrez ici les alertes que vous avez réglées.', 'stats.alert_test')[0];
    }

    /**
     * Surveillance (toutes les 5 minutes) : relit l'état, relance une session arrêtée et prévient
     * une fois les administrateurs quand le numéro est déconnecté depuis quelques minutes.
     */
    public function watch(int $companyId): void
    {
        $session = WhatsAppQrSession::forCompanyId($companyId);
        if (! $session->exists || ($session->connected_at === null && $session->disconnected_at === null)) {
            return;
        }

        try {
            $session = $this->refresh($companyId);
            // Service redémarré sans reprendre la session : relance (la connexion revient sans nouveau scan)
            if ($session->status === 'disconnected' && $session->disconnected_at !== null) {
                $this->waha->start();
            }
        } catch (MessagingException $e) {
            // Service injoignable : compté comme une déconnexion
            $session->forceFill([
                'status' => 'disconnected',
                'last_error' => $e->getMessage(),
                'checked_at' => now(),
                'disconnected_at' => $session->disconnected_at ?? now(),
            ])->save();
        }

        $lost = ! $session->isConnected() && $session->disconnected_at !== null && $session->warned_at === null
            && $session->disconnected_at->lte(now()->subMinutes((int) config('messaging.qr.warn_after', 10)));

        if ($lost) {
            $session->forceFill(['warned_at' => now()])->save();
            $admins = User::query()->where('company_id', $companyId)->whereNull('merchant_id')
                ->where('status', 'active')->role(Role::Admin->value)->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new AlertWhatsAppDisconnected($session));
            }
        }
    }
}
