<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\ClientCreditNote;
use App\Models\ClientInvoice;
use App\Models\Company;
use App\Models\Merchant;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Facture normalisée électronique (FNE) de la DGI, par API (procédure d'interfaçage, mai 2025) : chaque
 * facture du mois émise est certifiée (POST external/invoices/sign), chaque avoir aussi
 * (POST external/invoices/{id}/refund). La DGI renvoie la référence FNE et l'adresse de vérification, imprimée
 * en QR code.
 *
 * Données refusées (400) ou clé refusée (401) : rien n'est émis, le message de la FNE est affiché. FNE
 * injoignable : la pièce reste « à certifier » et part toute seule plus tard (fne:retry).
 */
class Fne
{
    public const TEST_URL = 'http://54.247.95.108/ws';

    public const PAYMENT_METHODS = ['deferred', 'transfer', 'mobile-money', 'check', 'cash', 'card'];

    public const TEMPLATES = ['B2B', 'B2C', 'B2G'];

    public const DEFAULTS = [
        'enabled' => false,
        // test (plateforme de test de la DGI) ou production (URL donnée par la DGI après validation)
        'mode' => 'test',
        'test_url' => self::TEST_URL,
        'production_url' => null,
        // Point de vente et établissement déclarés sur l'espace FNE
        'point_of_sale' => null,
        'establishment' => null,
        // Facture du mois : payée après émission (à terme)
        'payment_method' => 'deferred',
        'commercial_message' => null,
        'footer' => null,
    ];

    /** @return array<string, mixed> */
    public function settings(?Company $company): array
    {
        $saved = $company?->fne_settings ?? [];

        return [...self::DEFAULTS, ...self::clean(is_array($saved) ? $saved : [])];
    }

    public function enabled(?Company $company): bool
    {
        return $company !== null && $this->settings($company)['enabled'] && filled($company->fne_api_key) && filled($this->url($company));
    }

    public function url(Company $company): ?string
    {
        $settings = $this->settings($company);
        $url = $settings['mode'] === 'production' ? $settings['production_url'] : $settings['test_url'];

        return $url ? rtrim($url, '/') : null;
    }

    /** Modèle de facturation du client : choisi sur sa fiche, sinon B2B s'il a un NCC */
    public function template(Merchant $merchant): string
    {
        return in_array($merchant->fne_template, self::TEMPLATES, true) ? $merchant->fne_template : ($merchant->ncc ? 'B2B' : 'B2C');
    }

    /**
     * Ce qui empêche la certification de cette facture (à corriger avant l'émission).
     *
     * @return list<string>
     */
    public function missing(ClientInvoice $invoice): array
    {
        $company = $invoice->company;
        $merchant = $invoice->merchant()->withoutGlobalScopes()->first();
        $settings = $this->settings($company);
        $out = [];
        if (blank($settings['point_of_sale']) || blank($settings['establishment'])) {
            $out[] = 'le point de vente et l\'établissement FNE (Réglages › Facturation et TVA)';
        }
        if ((float) $invoice->vat_rate > 0 && ! in_array((int) round((float) $invoice->vat_rate), [18, 9], true)) {
            $out[] = 'un taux de TVA accepté par la FNE (18 % ou 9 %)';
        }
        if (blank($merchant->email)) {
            $out[] = 'l\'e-mail du client';
        }
        if (blank($merchant->phone)) {
            $out[] = 'le téléphone du client';
        }
        if ($this->template($merchant) === 'B2B' && blank($merchant->ncc)) {
            $out[] = 'le NCC du client (facture B2B)';
        }

        return $out;
    }

    /**
     * Certifie une facture émise. 400 ou 401 : statut « refusée » et BusinessRuleException (l'appelant peut
     * annuler l'émission) ; FNE injoignable : « à certifier », relancée plus tard.
     */
    public function certify(ClientInvoice $invoice): ClientInvoice
    {
        $company = $invoice->company;
        if ($missing = $this->missing($invoice)) {
            throw new BusinessRuleException('Pour la facture normalisée (FNE), renseignez : '.implode(', ', $missing).'.', 'fne');
        }
        [$payload, $references] = $this->payload($invoice);
        $response = $this->send($company, 'external/invoices/sign', $payload, $invoice);
        if ($response === null) {
            return $invoice;
        }

        $body = $response->json();
        $items = [];
        foreach ((array) data_get($body, 'invoice.items', []) as $i => $item) {
            $lineId = $references[$item['reference'] ?? ''] ?? (array_values($references)[$i] ?? null);
            if ($lineId !== null && isset($item['id'])) {
                $items[(string) $lineId] = $item['id'];
            }
        }
        $invoice->update([
            'fne_status' => 'certified',
            'fne_reference' => $body['reference'] ?? null,
            'fne_token' => $body['token'] ?? null,
            'fne_id' => data_get($body, 'invoice.id'),
            'fne_items' => $items,
            'fne_error' => null,
            'fne_certified_at' => now(),
        ]);
        $this->stickers($company, $body);

        return $invoice;
    }

    /**
     * Certifie un avoir : articles de la facture certifiée et quantités reprises.
     */
    public function refund(ClientCreditNote $note): ClientCreditNote
    {
        $invoice = $note->invoice()->withoutGlobalScopes()->firstOrFail();
        $company = $invoice->company;
        $map = (array) ($invoice->fne_items ?? []);
        $items = [];
        foreach ($note->lines as $line) {
            $id = $map[(string) $line->client_invoice_line_id] ?? null;
            if ($id === null) {
                throw new BusinessRuleException("« {$line->label} » n'est pas sur la facture normalisée : l'avoir ne peut pas être certifié.", 'lines');
            }
            $items[] = ['id' => $id, 'quantity' => $line->quantity];
        }
        $response = $this->send($company, 'external/invoices/'.$invoice->fne_id.'/refund', ['items' => $items], $note);
        if ($response === null) {
            return $note;
        }

        $body = $response->json();
        $note->update([
            'fne_status' => 'certified',
            'fne_reference' => $body['reference'] ?? null,
            'fne_token' => $body['token'] ?? null,
            'fne_id' => data_get($body, 'invoice.id'),
            'fne_error' => null,
            'fne_certified_at' => now(),
        ]);
        $this->stickers($company, $body);

        return $note;
    }

    /**
     * Corps de la demande de certification : chaque ligne facturée (prix unitaire hors taxe), les remises
     * en pourcentage du total hors taxe.
     *
     * @return array{0: array<string, mixed>, 1: array<string, int>} corps, référence d'article => ligne
     */
    public function payload(ClientInvoice $invoice): array
    {
        $company = $invoice->company;
        $merchant = $invoice->merchant()->withoutGlobalScopes()->first();
        $settings = $this->settings($company);
        $rate = (float) $invoice->vat_rate;
        $ttc = $invoice->vat_basis === 'ttc' && $rate > 0;
        $tax = $this->taxCode($invoice, $merchant);

        $items = [];
        $references = [];
        $positive = 0.0;
        $negative = 0.0;
        foreach ($invoice->lines()->get() as $line) {
            $unit = $ttc ? round($line->unit_price * 100 / (100 + $rate), 2) : (float) $line->unit_price;
            if ($line->amount < 0) {
                $negative += -$line->quantity * $unit;

                continue;
            }
            if ($line->amount === 0) {
                continue;
            }
            $positive += $line->quantity * $unit;
            $reference = 'L'.$line->id;
            $references[$reference] = $line->id;
            $items[] = [
                'taxes' => [$tax],
                'customTaxes' => [],
                'reference' => $reference,
                'description' => mb_substr($line->label, 0, 255),
                'quantity' => $line->quantity,
                'amount' => $unit,
                'discount' => 0,
            ];
        }
        $snapshot = $invoice->client_snapshot ?? [];

        return [[
            'invoiceType' => 'sale',
            'paymentMethod' => $settings['payment_method'],
            'template' => $this->template($merchant),
            'isRne' => false,
            'clientNcc' => $snapshot['ncc'] ?? $merchant->ncc ?? '',
            'clientCompanyName' => $snapshot['legal_name'] ?? ($merchant->legal_name ?: $merchant->business_name),
            'clientPhone' => str_replace(' ', '', PhoneNumber::display($merchant->phone)),
            'clientEmail' => $merchant->email,
            'pointOfSale' => $settings['point_of_sale'],
            'establishment' => $settings['establishment'],
            'commercialMessage' => $settings['commercial_message'] ?? '',
            'footer' => $settings['footer'] ?? '',
            'foreignCurrency' => '',
            'foreignCurrencyRate' => 0,
            'items' => $items,
            'customTaxes' => [],
            // Remises (lignes négatives) : pourcentage du total hors taxe
            'discount' => $positive > 0 ? round(min(100, $negative * 100 / $positive), 2) : 0,
        ], $references];
    }

    /** Type de TVA de la FNE : normale 18 %, réduite 9 %, exonération conventionnelle ou légale */
    public function taxCode(ClientInvoice $invoice, Merchant $merchant): string
    {
        $rate = (int) round((float) $invoice->vat_rate);

        return match (true) {
            $rate === 18 => 'TVA',
            $rate === 9 => 'TVAB',
            $invoice->vat_exemption !== null && $merchant->vat_exemption_type !== 'legal' => 'TVAC',
            default => 'TVAD',
        };
    }

    /**
     * Envoie la demande. Null : FNE injoignable, la pièce reste à certifier (relancée).
     */
    private function send(Company $company, string $path, array $payload, ClientInvoice|ClientCreditNote $document): ?Response
    {
        $document->increment('fne_attempts');
        try {
            $response = Http::acceptJson()->asJson()->withToken((string) $company->fne_api_key)->timeout(25)
                ->post($this->url($company).'/'.$path, $payload);
        } catch (ConnectionException $e) {
            Log::warning('FNE injoignable', ['document' => $document::class.'#'.$document->id, 'error' => $e->getMessage()]);
            $document->update(['fne_status' => 'pending', 'fne_error' => 'FNE injoignable : nouvel essai automatique.']);

            return null;
        }
        if ($response->successful()) {
            return $response;
        }
        if ($response->serverError()) {
            $document->update(['fne_status' => 'pending', 'fne_error' => 'FNE indisponible ('.$response->status().') : nouvel essai automatique.']);

            return null;
        }

        $message = $response->status() === 401
            ? 'clé API refusée (vérifiez la clé et le mode test ou production)'
            : (string) ($response->json('message') ?: 'demande refusée ('.$response->status().')');
        $errors = $response->json('errors');
        if (is_array($errors) && $errors !== []) {
            $message .= ' · '.mb_substr(json_encode($errors, JSON_UNESCAPED_UNICODE), 0, 300);
        }
        $document->update(['fne_status' => 'failed', 'fne_error' => mb_substr('FNE : '.$message, 0, 500)]);

        throw new BusinessRuleException('FNE : '.$message, 'fne');
    }

    private function stickers(Company $company, array $body): void
    {
        if (isset($body['balance_sticker'])) {
            $company->forceFill(['fne_balance_sticker' => (int) $body['balance_sticker'], 'fne_warning' => (bool) ($body['warning'] ?? false)])->save();
        }
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'fne_settings' => ['sometimes', 'nullable', 'array'],
            'fne_settings.enabled' => ['nullable', 'boolean'],
            'fne_settings.mode' => ['nullable', 'in:test,production'],
            'fne_settings.test_url' => ['nullable', 'url', 'max:255'],
            'fne_settings.production_url' => ['nullable', 'url', 'max:255'],
            'fne_settings.point_of_sale' => ['nullable', 'string', 'max:100'],
            'fne_settings.establishment' => ['nullable', 'string', 'max:150'],
            'fne_settings.payment_method' => ['nullable', 'in:'.implode(',', self::PAYMENT_METHODS)],
            'fne_settings.commercial_message' => ['nullable', 'string', 'max:255'],
            'fne_settings.footer' => ['nullable', 'string', 'max:255'],
            'fne_api_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
                'enabled' => (bool) $value,
                'mode' => in_array($value, ['test', 'production'], true) ? $value : null,
                'payment_method' => in_array($value, self::PAYMENT_METHODS, true) ? $value : null,
                default => mb_substr(trim((string) $value), 0, 255) ?: null,
            };
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
