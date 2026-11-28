<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ReturnHandover;
use App\Services\Orders\ReturnHandovers;

/**
 * Confirmation par WhatsApp de la remise des colis retournés : message préparé par le QR code du livreur
 * (« RETOUR R-4821 : j'ai bien reçu les 2 colis »), ou mot « retour » puis bouton « Oui, tout reçu » / « Il en manque ».
 * Le message vient du numéro enregistré du client (identifié avant) : c'est la preuve de la remise.
 */
class ReturnConversation
{
    private const WORDS = ['retour', 'retours', 'colis retour', 'colis retournes', 'retour colis'];

    public function __construct(private readonly ReturnHandovers $handovers) {}

    /**
     * @return array{0: string, 1: array<string, string>}|null réponse (texte, boutons), ou null si le message n'est pas pour nous
     */
    public function handle(Company $company, Merchant $merchant, string $phone, ?string $text, ?string $button): ?array
    {
        if ($button !== null) {
            return str_starts_with($button, 'rt:') ? $this->button($company, $merchant, $phone, $button) : null;
        }

        $normalized = RescheduleConversation::normalize((string) $text);

        // Message préparé par le QR code : la référence suit le mot « retour »
        if (preg_match('/^retour r ?(\d{4})\b/', $normalized, $m)) {
            return $this->byReference($company, $merchant, $phone, 'R-'.$m[1]);
        }

        if (in_array($normalized, self::WORDS, true)) {
            return $this->ask($company, $merchant);
        }

        return null;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function byReference(Company $company, Merchant $merchant, string $phone, string $reference): array
    {
        $handover = $this->handovers->findByReference($company, $merchant, $reference);

        if ($handover === null) {
            return ["Je ne trouve pas de remise {$reference} en attente pour vous. Si un livreur est chez vous, écrivez « retour ».", []];
        }

        if ($handover->isExpired()) {
            return ['Ce QR code a expiré. Demandez au livreur d\'en afficher un nouveau, ou écrivez « retour ».', []];
        }

        return $this->confirmed($merchant, $this->handovers->confirm($handover, 'whatsapp', $phone));
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function ask(Company $company, Merchant $merchant): array
    {
        $handover = $this->handovers->awaitingMerchant($company->id, $merchant->id)->first();

        if ($handover === null) {
            return ['Aucun retour de colis n\'attend votre confirmation pour le moment.', []];
        }

        $count = count($handover->order_ids);
        $courier = $handover->courier?->user?->name ?? 'Notre livreur';
        $intro = $handover->status === ReturnHandover::PENDING
            ? "Bonjour {$merchant->business_name}. {$courier} est chez vous avec *{$count} colis* à vous rendre :"
            : "Bonjour {$merchant->business_name}. {$courier} vous a rendu *{$count} colis* le {$handover->handed_at?->timezone($company->timezone ?: config('app.timezone'))->format('d/m à H:i')} :";

        return [
            $intro."\n".$this->list($handover)."\n\nLes avez-vous en main ?",
            ["rt:ok:{$handover->id}" => 'Oui, tout reçu', "rt:ko:{$handover->id}" => 'Il en manque'],
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function button(Company $company, Merchant $merchant, string $phone, string $button): array
    {
        [, $action, $id] = array_pad(explode(':', $button, 3), 3, null);
        $handover = $this->handovers->awaitingMerchant($company->id, $merchant->id)->firstWhere('id', (int) $id);

        if ($handover === null) {
            return ['Cette remise est déjà traitée. Merci 🙏', []];
        }

        if ($action === 'ko') {
            $this->handovers->dispute($handover, $phone);

            return ['C\'est noté : l\'agence vous appelle pour retrouver les colis manquants.', []];
        }

        return $this->confirmed($merchant, $this->handovers->confirm($handover, 'whatsapp_word', $phone));
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function confirmed(Merchant $merchant, ReturnHandover $handover): array
    {
        $count = count($handover->order_ids);
        $courier = $handover->courier?->user?->name ?? 'notre livreur';

        return [
            "Merci {$merchant->business_name}, c'est noté.\n*{$count} colis rendu".($count > 1 ? 's' : '')."* par {$courier} :\n"
                .$this->list($handover)."\n\nUn souci avec ces colis ? Répondez à ce message.",
            [],
        ];
    }

    private function list(ReturnHandover $handover): string
    {
        return $handover->orders()
            ->map(fn (Order $o) => '· '.$o->tracking_code.($o->recipient_name ? " ({$o->recipient_name})" : ''))
            ->implode("\n");
    }
}
