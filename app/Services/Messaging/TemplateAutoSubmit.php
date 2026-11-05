<?php

namespace App\Services\Messaging;

use App\Enums\WhatsAppTemplate;
use App\Jobs\RefreshWhatsAppTemplates;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use Illuminate\Support\Facades\Cache;

/**
 * Modèles WhatsApp soumis à Meta sans intervention : quand une page en a besoin (ex. la caisse pour le reçu de
 * versement) et chaque nuit, les modèles jamais soumis partent chez Meta et l'état des autres est relu.
 * Ne concerne que les numéros reliés par l'API officielle de Meta.
 */
class TemplateAutoSubmit
{
    public function __construct(private WhatsAppConnection $connection, private MetaCloudGateway $gateway) {}

    /** Numéro de l'entreprise qui a besoin de modèles approuvés par Meta */
    public static function needsTemplates(?WhatsAppAccount $account): bool
    {
        return $account !== null && $account->isLive() && filled($account->waba_id);
    }

    /**
     * État commun des modèles demandés : approved, pending ou rejected ; null quand il n'y a rien à faire (pas de
     * numéro officiel). Un modèle pas encore soumis ou en attente déclenche, au plus une fois toutes les 10 minutes,
     * la soumission et la relecture de l'état chez Meta.
     *
     * @param  list<WhatsAppTemplate>  $templates
     */
    public function ensure(int $companyId, array $templates): ?string
    {
        $account = WhatsAppAccount::forCompanyId($companyId);
        if (! self::needsTemplates($account)) {
            return null;
        }

        $known = $account->templates()->where('language', WhatsAppTemplate::LANGUAGE)
            ->whereIn('name', array_map(fn (WhatsAppTemplate $t) => $t->value, $templates))->pluck('status', 'name');
        $statuses = array_map(fn (WhatsAppTemplate $t) => $known[$t->value] ?? null, $templates);

        if (in_array('rejected', $statuses, true)) {
            return 'rejected';
        }
        if ($statuses === array_fill(0, count($templates), 'approved')) {
            return 'approved';
        }

        if (Cache::add('whatsapp-templates:'.$account->id, true, 600)) {
            RefreshWhatsAppTemplates::dispatch($account->id);
        }

        return 'pending';
    }

    /**
     * Relit l'état chez Meta puis soumet les modèles jamais soumis. Renvoie le nombre de modèles soumis.
     */
    public function refresh(WhatsAppAccount $account): int
    {
        if (! self::needsTemplates($account)) {
            return 0;
        }

        try {
            foreach ($this->gateway->templates($account) as $template) {
                if (WhatsAppTemplate::tryFrom($template['name']) === null) {
                    continue;
                }
                $account->templates()->updateOrCreate(
                    ['name' => $template['name'], 'language' => $template['language']],
                    ['status' => $template['status'], 'category' => $template['category'], 'rejected_reason' => $template['rejected_reason'], 'synced_at' => now()],
                );
            }

            return count(array_filter($this->connection->submitTemplates($account, onlyNew: true), fn ($r) => $r === 'ok'));
        } catch (MessagingException $e) {
            report($e);

            return 0;
        }
    }
}
