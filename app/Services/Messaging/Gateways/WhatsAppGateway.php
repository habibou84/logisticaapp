<?php

namespace App\Services\Messaging\Gateways;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingException;

interface WhatsAppGateway
{
    /**
     * Envoie un modèle approuvé et renvoie l'identifiant du message chez le fournisseur.
     *
     * @param  list<string>  $params
     *
     * @throws MessagingException
     */
    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $language, array $params): string;

    /**
     * Réponse libre dans une conversation ouverte par l'expéditeur (fenêtre de 24 h),
     * avec jusqu'à 3 boutons de réponse rapide (identifiant => libellé).
     *
     * @param  array<string, string>  $buttons
     *
     * @throws MessagingException
     */
    public function sendReply(?WhatsAppAccount $account, string $to, string $text, array $buttons = []): string;

    /**
     * Modèles connus du fournisseur pour ce compte.
     *
     * @return list<array{name: string, language: string, status: string, category: ?string, rejected_reason: ?string}>
     *
     * @throws MessagingException
     */
    public function templates(?WhatsAppAccount $account): array;
}
