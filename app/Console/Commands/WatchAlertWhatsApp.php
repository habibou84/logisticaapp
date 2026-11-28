<?php

namespace App\Console\Commands;

use App\Models\WhatsAppQrSession;
use App\Services\Messaging\AlertWhatsApp;
use App\Services\Messaging\Gateways\WahaClient;
use Illuminate\Console\Command;

class WatchAlertWhatsApp extends Command
{
    protected $signature = 'whatsapp-qr:watch';

    protected $description = 'WhatsApp des alertes (QR code) : état relu, session relancée, administrateurs prévenus d\'une déconnexion';

    public function handle(AlertWhatsApp $alerts): int
    {
        if (! WahaClient::available()) {
            return self::SUCCESS;
        }

        WhatsAppQrSession::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNotNull('connected_at')->orWhereNotNull('disconnected_at'))
            ->pluck('company_id')
            ->each(fn (int $companyId) => $alerts->watch($companyId));

        return self::SUCCESS;
    }
}
