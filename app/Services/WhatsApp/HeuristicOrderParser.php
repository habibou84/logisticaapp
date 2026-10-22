<?php

namespace App\Services\WhatsApp;

use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Analyse par règles : numéros ivoiriens, montants, communes connues et format
 * « Nom : … / Tél : … / Commune : … ». Toujours disponible, sans service externe.
 */
class HeuristicOrderParser implements OrderMessageParser
{
    private const LABELS = [
        'recipient_name' => 'nom|client|cliente|destinataire|pour',
        'recipient_phone' => 'tel|tél|telephone|téléphone|numero|numéro|contact|num',
        'zone' => 'commune|zone|quartier|lieu|ville|destination',
        'delivery_address' => 'adresse|addresse|adr',
        'delivery_landmark' => 'repere|repère|reperes|repères',
        'items_amount' => 'montant|prix|total|a encaisser|à encaisser|somme',
        'description' => 'article|articles|produit|produits|colis|commande',
        'merchant_note' => 'note|info|infos|remarque',
    ];

    // Numéro ivoirien à 10 chiffres, éventuellement précédé de +225 / 00225
    private const PHONE = '/(?<!\d)(?:(?:\+|00)225[\s.\-]?)?0[1-9](?:[\s.\-]?\d{2}){4}(?!\d)/u';

    public function __construct(private readonly ZoneMatcher $zones) {}

    public function parse(string $text, Collection $zones, ?string $senderPhone = null): array
    {
        $result = [];
        $labeled = $this->labeledLines($text);

        // Téléphones (hors numéro de l'expéditeur)
        $phones = $this->phones($labeled['recipient_phone'] ?? '')
            ->merge($this->phones($text))
            ->unique()
            ->reject(fn ($p) => $p === $senderPhone)
            ->values();
        if ($phones->isNotEmpty()) {
            $result['recipient_phone'] = $phones[0];
        }
        if ($phones->count() > 1) {
            $result['recipient_phone2'] = $phones[1];
        }

        // Montant
        $amount = isset($labeled['items_amount']) ? $this->amount($labeled['items_amount'], true) : $this->amount($text);
        if ($amount !== null) {
            $result['items_amount'] = $amount;
        }

        // Zone : ligne « Commune : … » d'abord, sinon tout le texte
        $found = isset($labeled['zone']) ? $this->zones->answer($labeled['zone'], $zones) : collect();
        if ($found->isEmpty()) {
            $found = $this->zones->inText(preg_replace(self::PHONE, ' ', $text), $zones);
        }
        if ($found->isNotEmpty()) {
            $result['zones'] = $found->pluck('id')->all();
        }

        // « Yopougon Siporex face… » : les mots qui suivent la commune forment l'adresse
        if (! isset($labeled['delivery_address']) && $found->isNotEmpty()) {
            $address = $this->addressAfter($text, $found->first()->name);
            if ($address !== null) {
                $result['delivery_address'] = $address;
            }
        }

        foreach (['recipient_name', 'delivery_address', 'delivery_landmark', 'description', 'merchant_note'] as $field) {
            if (filled($labeled[$field] ?? null)) {
                $result[$field] = Str::limit(trim($labeled[$field]), 250, '');
            }
        }

        $result['recipient_name'] ??= $this->name($text);
        $result['delivery_landmark'] ??= $this->landmark($text);

        if ($payer = $this->feePayer($text)) {
            $result['fee_payer'] = $payer;
        }

        return array_filter($result, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Réponse à une question précise (« Quel montant ? ») : on ne lit que ce champ.
     */
    public function amount(string $text, bool $labeled = false): ?int
    {
        $text = preg_replace(self::PHONE, ' ', $text);
        preg_match_all('/(?<![\d.])(\d{1,3}(?:[ .\x{202F}\x{00A0}]\d{3})+|\d+)\s*(k\b|mille\b|f\b|fr\b|frs\b|fcfa\b|cfa\b|francs?\b|xof\b)?/iu', $text, $matches, PREG_SET_ORDER);

        $candidates = collect($matches)->map(function ($m) {
            $value = (int) preg_replace('/\D/', '', $m[1]);
            $suffix = Str::lower($m[2] ?? '');
            if (in_array($suffix, ['k', 'mille'], true)) {
                $value *= 1000;
            }

            return ['value' => $value, 'currency' => $suffix !== ''];
        });

        if ($labeled) {
            return $candidates->first()['value'] ?? null;
        }

        // « 15000 F » l'emporte ; sinon le plus grand nombre plausible (les petits sont des quantités)
        $withCurrency = $candidates->firstWhere('currency', true);
        if ($withCurrency) {
            return $withCurrency['value'];
        }

        $plausible = $candidates->filter(fn ($c) => $c['value'] >= 100 && $c['value'] <= 10_000_000);

        return $plausible->isEmpty() ? null : $plausible->max('value');
    }

    /**
     * @return Collection<int, string>
     */
    public function phones(string $text): Collection
    {
        preg_match_all(self::PHONE, $text, $matches);

        return collect($matches[0])->map(fn ($p) => PhoneNumber::normalize($p))->filter()->values();
    }

    /**
     * @return array<string, string>
     */
    private function labeledLines(string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            foreach (self::LABELS as $field => $labels) {
                if (preg_match('/^\s*[-•*]?\s*(?:'.$labels.')\s*[:=\-]\s*(.+)$/iu', $line, $m)) {
                    $found[$field] ??= trim($m[1]);
                    break;
                }
            }
        }

        return $found;
    }

    private function addressAfter(string $text, string $zoneName): ?string
    {
        if (! preg_match('/\b'.preg_quote($zoneName, '/').'\b[\s,:-]+([^,.;!?\n]{2,60})/iu', $text, $m)) {
            return null;
        }

        // On s'arrête au repère (« face… ») et aux numéros
        $address = preg_split('/\b(?:face|en face|derri[eè]re|à côté|a cote|pr[eè]s|non loin|son|sa|tel|tél|num[ée]ro|\d{2}\s?\d{2})\b/iu', $m[1])[0];
        // Formules de politesse : pas une adresse
        $address = preg_replace('/\b(svp|stp|s\'il vous pla[iî]t|s\'il te pla[iî]t|merci|please|pls)\b/iu', '', $address);
        $address = trim(preg_replace('/\s+/u', ' ', $address), " \t-");

        return mb_strlen($address) >= 2 ? $address : null;
    }

    private function name(string $text): ?string
    {
        if (preg_match('/\b(?:pour|client(?:e)?|destinataire|chez|livrer à|livrer a)\s*:?\s+((?:m(?:me|lle|r)?\.?\s+)?\p{Lu}[\p{L}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+)?)/u', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function landmark(string $text): ?string
    {
        if (preg_match('/\b((?:face|en face|derri[eè]re|à côté|a cote|pr[eè]s|non loin)\s+(?:de\s+|du\s+|des\s+|la\s+|le\s+|l\')?[^\n,.;]{3,80})/iu', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function feePayer(string $text): ?string
    {
        $t = ZoneMatcher::normalize($text);

        if (preg_match('/(livraison|frais)( de livraison)? (a la charge du|payee? par le|pour le|par le) client|client paie (la )?livraison|livraison en sus|plus (la )?livraison/', $t)) {
            return 'recipient';
        }

        if (preg_match('/(livraison|frais)( de livraison)? (a ma charge|offerte?|inclus|incluse|comprise?)|livraison gratuite/', $t)) {
            return 'merchant';
        }

        return null;
    }
}
