<?php

namespace App\Services\Orders;

use App\Enums\FeePayer;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Zone;
use App\Services\Pricing\PricingService;
use App\Services\WhatsApp\ZoneMatcher;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Import de courses depuis un fichier CSV ou Excel (une ligne = une course).
 * Les en-têtes sont reconnus sous plusieurs noms (« Téléphone », « Tel », « Commune »…),
 * chaque ligne est contrôlée et chiffrée avant toute création.
 */
class OrderImporter
{
    public const MAX_ROWS = 500;

    // En-tête normalisé (minuscules, sans accents ni espaces) → champ
    private const COLUMNS = [
        'recipient_name' => ['nom', 'nomclient', 'client', 'destinataire', 'nomdudestinataire', 'name'],
        'recipient_phone' => ['telephone', 'tel', 'telephoneclient', 'numero', 'contact', 'phone', 'mobile'],
        'recipient_phone2' => ['telephone2', 'tel2', 'autretelephone', 'autrenumero', 'phone2'],
        'commune' => ['commune', 'ville', 'zone', 'zonedelivraison'],
        'quartier' => ['quartier', 'souszone'],
        'delivery_address' => ['adresse', 'adressedelivraison', 'address'],
        'delivery_landmark' => ['repere', 'pointderepere', 'landmark'],
        'items_amount' => ['montant', 'montantaencaisser', 'montantarticles', 'prix', 'total', 'amount'],
        'fee_payer' => ['fraispayespar', 'livraisonpayeepar', 'quipaielalivraison', 'payeur', 'feepayer'],
        'description' => ['description', 'contenu', 'colis', 'articles', 'produits'],
        'merchant_reference' => ['reference', 'ref', 'commande', 'numerodecommande', 'numerocommande'],
        'delivery_scheduled_date' => ['date', 'datedelivraison', 'datelivraison'],
        'merchant_note' => ['note', 'instruction', 'instructions', 'commentaire'],
    ];

    public function __construct(
        private readonly ZoneMatcher $zones,
        private readonly PricingService $pricing,
        private readonly OrderService $orders,
    ) {}

    /**
     * Modèle à remplir (CSV « ; », lisible directement par Excel).
     */
    public static function template(): string
    {
        $rows = [
            ['Nom', 'Téléphone', 'Téléphone 2', 'Commune', 'Quartier', 'Adresse', 'Repère', 'Montant', 'Frais payés par', 'Description', 'Référence', 'Date de livraison', 'Note'],
            ['Awa Koné', '07 08 09 10 11', '', 'Yopougon', 'Siporex', 'Rue des jardins', 'Face pharmacie', '15000', 'client', '2 robes', 'CMD-1001', '', 'Appeler avant'],
            ['Jean Kouadio', '05 44 33 22 11', '', 'Cocody', '', 'Riviera 3', '', '0', 'moi', '1 sac (déjà payé)', 'CMD-1002', '', ''],
        ];

        return "\u{FEFF}".implode("\r\n", array_map(fn ($r) => implode(';', array_map(fn ($v) => str_contains($v, ';') ? '"'.$v.'"' : $v, $r)), $rows))."\r\n";
    }

    /**
     * Lit le fichier et contrôle chaque ligne, sans rien créer.
     *
     * @return array{rows: list<array>, valid: int, invalid: int, total_fees: int, total_cod: int}
     */
    public function preview(Merchant $merchant, string $path, string $extension): array
    {
        $zones = Zone::forCompany($merchant->company_id)->active()->with('parent')->get();
        $rows = [];

        foreach ($this->read($path, $extension) as $line => $raw) {
            $rows[] = $this->check($merchant, $zones, $line, $raw);
        }

        if ($rows === []) {
            throw new BusinessRuleException('Le fichier ne contient aucune course.', 'file');
        }

        $valid = array_filter($rows, fn ($r) => $r['errors'] === []);

        return [
            'rows' => $rows,
            'valid' => count($valid),
            'invalid' => count($rows) - count($valid),
            'total_fees' => array_sum(array_column($valid, 'delivery_fee')),
            'total_cod' => array_sum(array_column($valid, 'cod_amount')),
        ];
    }

    /**
     * Crée les courses des lignes valides.
     *
     * @return array{created: list<array{line: int, tracking_code: string, id: int}>, errors: list<array{line: int, message: string}>}
     */
    public function import(User $actor, Merchant $merchant, string $path, string $extension, bool $skipInvalid): array
    {
        $preview = $this->preview($merchant, $path, $extension);

        if ($preview['invalid'] > 0 && ! $skipInvalid) {
            throw new BusinessRuleException("{$preview['invalid']} ligne(s) comportent des erreurs : corrigez le fichier ou importez seulement les lignes valides.", 'file');
        }

        $result = ['created' => [], 'errors' => []];

        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== []) {
                continue;
            }

            try {
                $order = $this->orders->create($actor, $merchant, $row['data'], 'import');
                $result['created'][] = ['line' => $row['line'], 'tracking_code' => $order->tracking_code, 'id' => $order->id];
            } catch (BusinessRuleException $e) {
                $result['errors'][] = ['line' => $row['line'], 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>> lignes indexées par numéro de ligne du fichier
     */
    private function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if ($extension === 'xlsx') {
            $reader = new XlsxReader;
        } elseif (in_array($extension, ['csv', 'txt'], true)) {
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->delimiter($path)));
        } else {
            throw new BusinessRuleException('Format non pris en charge : envoyez un fichier .csv ou .xlsx.', 'file');
        }

        try {
            $reader->open($path);
            $header = null;
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $values = $row->toArray();

                    if ($header === null) {
                        $header = $this->mapHeader($values);

                        continue;
                    }

                    if (count(array_filter($values, fn ($v) => trim((string) ($v instanceof DateTimeInterface ? 'x' : $v)) !== '')) === 0) {
                        continue;
                    }

                    if (count($rows) >= self::MAX_ROWS) {
                        throw new BusinessRuleException('Au plus '.self::MAX_ROWS.' courses par fichier.', 'file');
                    }

                    $mapped = [];
                    foreach ($header as $index => $field) {
                        $mapped[$field] = $values[$index] ?? null;
                    }
                    $rows[$line] = $mapped;
                }

                break; // première feuille seulement
            }
        } catch (BusinessRuleException $e) {
            throw $e;
        } catch (Throwable) {
            throw new BusinessRuleException('Fichier illisible : vérifiez qu\'il s\'agit bien d\'un fichier CSV ou Excel (.xlsx).', 'file');
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string> index de colonne → champ
     */
    private function mapHeader(array $values): array
    {
        $aliases = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                $aliases[$name] = $field;
            }
        }

        $header = [];
        foreach ($values as $index => $value) {
            $key = str_replace(' ', '', ZoneMatcher::normalize(preg_replace('/^\x{FEFF}/u', '', (string) $value)));
            if (isset($aliases[$key]) && ! in_array($aliases[$key], $header, true)) {
                $header[$index] = $aliases[$key];
            }
        }

        foreach (['recipient_phone', 'commune'] as $required) {
            if (! in_array($required, $header, true) && ! ($required === 'commune' && in_array('quartier', $header, true))) {
                throw new BusinessRuleException('Colonnes obligatoires introuvables : « Téléphone » et « Commune » (téléchargez le modèle).', 'file');
            }
        }

        return $header;
    }

    /**
     * @param  Collection<int, Zone>  $zones
     */
    private function check(Merchant $merchant, Collection $zones, int $line, array $raw): array
    {
        $text = fn (string $field) => ($v = trim((string) ($raw[$field] ?? ''))) === '' ? null : $v;
        $errors = [];

        $phone = $this->phone($raw['recipient_phone'] ?? null);
        if ($phone === null) {
            $errors[] = $text('recipient_phone') ? 'Téléphone invalide : '.$text('recipient_phone') : 'Téléphone manquant';
        }

        $phone2 = $text('recipient_phone2') ? $this->phone($raw['recipient_phone2']) : null;
        if ($text('recipient_phone2') && $phone2 === null) {
            $errors[] = 'Second téléphone invalide';
        }

        $zone = $this->zone($text('commune'), $text('quartier'), $zones);
        if ($zone === null) {
            $errors[] = ($text('commune') || $text('quartier'))
                ? 'Commune inconnue : '.trim($text('commune').' '.$text('quartier'))
                : 'Commune manquante';
        }

        $amount = $this->amount($raw['items_amount'] ?? null);
        if ($amount === false) {
            $errors[] = 'Montant invalide : '.$text('items_amount');
        }

        $feePayer = $this->feePayer($text('fee_payer')) ?? $merchant->default_fee_payer;

        $date = $this->date($raw['delivery_scheduled_date'] ?? null);
        if ($date === false) {
            $errors[] = 'Date invalide : '.$text('delivery_scheduled_date');
        } elseif ($date !== null && Carbon::parse($date)->isBefore(today())) {
            $errors[] = 'Date de livraison passée';
        }

        $data = array_filter([
            'recipient_name' => $text('recipient_name'),
            'recipient_phone' => $phone,
            'recipient_phone2' => $phone2,
            'delivery_zone_id' => $zone?->id,
            'delivery_address' => $text('delivery_address'),
            'delivery_landmark' => $text('delivery_landmark'),
            'items_amount' => $amount === false ? null : $amount,
            'fee_payer' => $feePayer->value,
            'description' => $text('description'),
            'merchant_reference' => $text('merchant_reference'),
            'delivery_scheduled_date' => $date ?: null,
            'merchant_note' => $text('merchant_note'),
        ], fn ($v) => $v !== null);

        $fee = 0;
        if ($zone && $errors === []) {
            try {
                $pickup = $zones->firstWhere('id', $merchant->pickup_zone_id) ?? Zone::findOrFail($merchant->pickup_zone_id);
                $fee = $this->pricing->quote($merchant, $pickup, $zone, [])->total();
            } catch (BusinessRuleException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'line' => $line,
            'data' => $data,
            'zone_name' => $zone?->fullName(),
            'delivery_fee' => $fee,
            'cod_amount' => (int) ($data['items_amount'] ?? 0) + ($feePayer === FeePayer::Recipient ? $fee : 0),
            'errors' => $errors,
        ];
    }

    private function phone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) (is_float($value) ? number_format($value, 0, '', '') : $value));

        // Excel retire le 0 initial des numéros saisis comme nombres
        if (strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return PhoneNumber::normalize(str_starts_with(trim((string) $value), '+') ? '+'.$digits : $digits);
    }

    /**
     * @param  Collection<int, Zone>  $zones
     */
    private function zone(?string $commune, ?string $quartier, Collection $zones): ?Zone
    {
        $parent = $commune ? ($this->zones->byName($commune, $zones) ?? $this->zones->answer($commune, $zones)->first()) : null;

        if ($quartier) {
            $candidates = $parent ? $zones->where('parent_id', $parent->id) : $zones;
            $child = $this->zones->byName($quartier, $candidates) ?? $this->zones->answer($quartier, $candidates)->first();
            if ($child) {
                return $child;
            }
        }

        return $parent;
    }

    private function amount(mixed $value): int|false|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? (int) round($value) : false;
        }

        $text = mb_strtolower(trim((string) $value));
        $thousands = preg_match('/\d\s*k$/', $text) === 1;
        $digits = preg_replace('/\D+/', '', preg_replace('/[.,]\d{1,2}\s*(f|fcfa|cfa|xof)?$/', '', $text));

        if ($digits === '' || preg_match('/[a-z]/', preg_replace('/(k|f|fcfa|cfa|xof|francs?)/', '', $text))) {
            return false;
        }

        return (int) $digits * ($thousands ? 1000 : 1);
    }

    private function feePayer(?string $value): ?FeePayer
    {
        if ($value === null) {
            return null;
        }

        $value = ZoneMatcher::normalize($value);

        return match (true) {
            in_array($value, ['client', 'destinataire', 'recipient', 'acheteur'], true) => FeePayer::Recipient,
            in_array($value, ['moi', 'marchand', 'boutique', 'vendeur', 'merchant'], true) => FeePayer::Merchant,
            default => null,
        };
    }

    private function date(mixed $value): string|false|null
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }
            if ($date && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return false;
    }

    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        fclose($handle);

        return collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
    }
}
