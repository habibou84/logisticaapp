<?php

namespace App\Services\Finance;

use App\Models\ClientInvoice;
use App\Models\Company;
use App\Models\Merchant;
use Carbon\CarbonImmutable;

/**
 * TVA des factures des clients (Réglages › Facturation et TVA) : activée ou non, taux, prix des formules et
 * tarifs saisis hors taxe (TVA ajoutée) ou toutes taxes comprises (TVA incluse), mention d'exonération. Un
 * client exonéré (attestation en cours de validité) est facturé sans TVA. Montants en francs entiers.
 */
class Vat
{
    public const BASES = ['ht', 'ttc'];

    public const DEFAULTS = [
        'enabled' => false,
        'rate' => 18,
        // Prix saisis dans les formules, tarifs, suppléments et lignes à la main
        'prices' => 'ht',
        // Imprimée sur la facture d'un client exonéré, avant la référence de son attestation
        'exemption_mention' => 'Exonéré de TVA',
    ];

    /** @return array<string, mixed> */
    public function for(?Company $company): array
    {
        $saved = $company?->vat_settings ?? [];

        return [...self::DEFAULTS, ...self::clean(is_array($saved) ? $saved : [])];
    }

    /** Exonération du client à cette date : attestation sans fin, ou pas encore échue */
    public function exempt(Merchant $merchant, ?CarbonImmutable $on = null): bool
    {
        if (! $merchant->vat_exempt) {
            return false;
        }
        $on ??= CarbonImmutable::today();

        return $merchant->vat_exemption_until === null || ! $merchant->vat_exemption_until->lt($on->startOfDay());
    }

    /**
     * Taux, base et mention d'exonération d'une facture de ce client.
     *
     * @return array{vat_rate: float, vat_basis: string, vat_exemption: ?string}
     */
    public function terms(Merchant $merchant): array
    {
        $settings = $this->for($merchant->company);
        if (! $settings['enabled']) {
            return ['vat_rate' => 0.0, 'vat_basis' => $settings['prices'], 'vat_exemption' => null];
        }
        if ($this->exempt($merchant)) {
            $ref = $merchant->vat_exemption_ref ? ' · attestation n° '.$merchant->vat_exemption_ref : '';

            return ['vat_rate' => 0.0, 'vat_basis' => $settings['prices'], 'vat_exemption' => mb_substr($settings['exemption_mention'].$ref, 0, 255)];
        }

        return ['vat_rate' => (float) $settings['rate'], 'vat_basis' => $settings['prices'], 'vat_exemption' => null];
    }

    /**
     * Hors taxe, TVA et TTC d'une somme saisie dans la base donnée.
     *
     * @return array{total_ht: int, vat_amount: int, total: int}
     */
    public static function split(int $sum, float $rate, string $basis): array
    {
        $sum = max(0, $sum);
        if ($rate <= 0) {
            return ['total_ht' => $sum, 'vat_amount' => 0, 'total' => $sum];
        }
        if ($basis === 'ttc') {
            $ht = (int) round($sum * 100 / (100 + $rate));

            return ['total_ht' => $ht, 'vat_amount' => $sum - $ht, 'total' => $sum];
        }
        $vat = (int) round($sum * $rate / 100);

        return ['total_ht' => $sum, 'vat_amount' => $vat, 'total' => $sum + $vat];
    }

    /** Montant TTC (à payer) d'une somme de lignes de cette facture */
    public static function gross(ClientInvoice $invoice, int $sum): int
    {
        return self::split($sum, (float) $invoice->vat_rate, $invoice->vat_basis ?? 'ht')['total'];
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'vat_settings' => ['sometimes', 'nullable', 'array'],
            'vat_settings.enabled' => ['nullable', 'boolean'],
            'vat_settings.rate' => ['nullable', 'numeric', 'between:0,50'],
            'vat_settings.prices' => ['nullable', 'in:'.implode(',', self::BASES)],
            'vat_settings.exemption_mention' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $value = match ($key) {
                'rate' => round(min(50, max(0, (float) $value)), 2),
                'prices' => in_array($value, self::BASES, true) ? $value : null,
                'exemption_mention' => mb_substr(trim((string) $value), 0, 160) ?: null,
                default => (bool) $value,
            };
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
