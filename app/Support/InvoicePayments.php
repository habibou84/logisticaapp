<?php

namespace App\Support;

use App\Enums\InvoicePaymentMethod;
use App\Models\Company;

/**
 * Moyens de paiement des factures clients, tels que l'entreprise les a choisis (Réglages › Entreprise) :
 * autant de comptes qu'elle veut, chacun proposé ou non aux clients.
 *
 *  - virement : banque, titulaire, IBAN, BIC ;
 *  - Mobile Money : opérateur (Orange Money, MTN MoMo, Moov Money, Wave…), numéro, titulaire ;
 *  - chèque : à l'ordre de ;
 *  - espèces, autre : précisions libres.
 */
class InvoicePayments
{
    /** Champs de chaque moyen, dans l'ordre d'affichage : clé → [libellé, à copier] */
    private const FIELDS = [
        'bank_transfer' => ['bank' => ['Banque', false], 'holder' => ['Titulaire', false], 'iban' => ['IBAN', true], 'bic' => ['BIC', true]],
        'mobile_money' => ['phone' => ['Numéro', true], 'holder' => ['Au nom de', false]],
        'cheque' => ['payee' => ['À l\'ordre de', false]],
        'cash' => ['details' => ['Où', false]],
        'other' => ['details' => ['Précisions', false]],
    ];

    /**
     * Règles de validation du réglage (UpdateCompanyRequest).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $methods = array_map(fn (InvoicePaymentMethod $m) => $m->value, InvoicePaymentMethod::cases());

        return [
            'invoice_payment_methods' => ['sometimes', 'nullable', 'array', 'max:12'],
            'invoice_payment_methods.*.method' => ['required', 'in:'.implode(',', $methods)],
            'invoice_payment_methods.*.enabled' => ['required', 'boolean'],
            'invoice_payment_methods.*.bank' => ['nullable', 'required_if:invoice_payment_methods.*.method,bank_transfer', 'string', 'max:80'],
            'invoice_payment_methods.*.iban' => ['nullable', 'required_if:invoice_payment_methods.*.method,bank_transfer', 'string', 'max:50'],
            'invoice_payment_methods.*.bic' => ['nullable', 'string', 'max:20'],
            'invoice_payment_methods.*.operator' => ['nullable', 'required_if:invoice_payment_methods.*.method,mobile_money', 'string', 'max:40'],
            'invoice_payment_methods.*.phone' => ['nullable', 'required_if:invoice_payment_methods.*.method,mobile_money', 'string', 'max:30'],
            'invoice_payment_methods.*.holder' => ['nullable', 'string', 'max:80'],
            'invoice_payment_methods.*.payee' => ['nullable', 'required_if:invoice_payment_methods.*.method,cheque', 'string', 'max:80'],
            'invoice_payment_methods.*.label' => ['nullable', 'required_if:invoice_payment_methods.*.method,other', 'string', 'max:60'],
            'invoice_payment_methods.*.details' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * Réglage nettoyé avant l'enregistrement : seulement les champs de chaque moyen.
     *
     * @param  list<array<string, mixed>>|null  $methods
     * @return list<array<string, mixed>>|null
     */
    public static function clean(?array $methods): ?array
    {
        if ($methods === null) {
            return null;
        }

        return array_values(array_map(function (array $m) {
            $keys = [...array_keys(self::FIELDS[$m['method']]), ...match ($m['method']) {
                'mobile_money' => ['operator'],
                'other' => ['label'],
                default => [],
            }];
            $fields = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_intersect_key($m, array_flip($keys)));

            // IBAN et BIC : en majuscules, par groupes de quatre pour la lecture
            if (isset($fields['iban'])) {
                $fields['iban'] = trim(chunk_split(strtoupper(preg_replace('/\s+/', '', $fields['iban'])), 4, ' '));
            }
            if (isset($fields['bic'])) {
                $fields['bic'] = strtoupper(preg_replace('/\s+/', '', $fields['bic']));
            }

            return ['method' => $m['method'], 'enabled' => (bool) $m['enabled'], ...array_filter($fields, fn ($v) => $v !== null && $v !== '')];
        }, $methods));
    }

    /**
     * Moyens proposés aux clients, prêts à afficher (facture, application).
     *
     * @return list<array{method: string, title: string, lines: list<array{label: string, value: string, copy: bool}>}>
     */
    public static function forClients(?Company $company): array
    {
        $methods = array_filter($company?->invoice_payment_methods ?? [], fn ($m) => ($m['enabled'] ?? false) && isset(self::FIELDS[$m['method'] ?? '']));

        return array_values(array_map(function (array $m) {
            $type = InvoicePaymentMethod::from($m['method']);
            $lines = [];
            foreach (self::FIELDS[$m['method']] as $key => [$label, $copy]) {
                if (filled($m[$key] ?? null)) {
                    $lines[] = ['label' => $label, 'value' => (string) $m[$key], 'copy' => $copy];
                }
            }

            return [
                'method' => $m['method'],
                'title' => match ($type) {
                    InvoicePaymentMethod::BankTransfer => 'Virement bancaire',
                    InvoicePaymentMethod::MobileMoney => (string) ($m['operator'] ?? 'Mobile Money'),
                    InvoicePaymentMethod::Other => (string) ($m['label'] ?? 'Autre'),
                    default => $type->label(),
                },
                'lines' => $lines,
            ];
        }, $methods));
    }
}
