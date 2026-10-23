<?php

namespace App\Services\Pricing;

use App\Enums\DeliveryDelay;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\InteriorRate;
use App\Models\Zone;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Import des tarifs des villes de l'intérieur depuis un fichier CSV ou Excel : une ligne par ville et par
 * délai (pli 0-500 g, colis 0-5 kg, kilogramme au-delà de 5 kg). Une ville déjà présente est mise à jour ;
 * une ville sans zone de livraison reçoit la sienne.
 */
class InteriorRateImporter
{
    private const MAX_ROWS = 2000;

    /** Colonnes reconnues (en-têtes sans accents ni majuscules) */
    private const COLUMNS = [
        'city' => ['ville', 'city', 'localite', 'destination'],
        'delay' => ['delai', 'delais', 'delai de livraison', 'delay'],
        'pli' => ['pli', 'plis', 'prix pli', 'pli 0-500g', 'pli (0-500 g)', 'pli 0-500 g'],
        'colis' => ['colis', 'prix colis', 'colis 0-5kg', 'colis (0-5 kg)', 'colis 0-5 kg'],
        'extra_kg' => ['kg supplementaire', 'kg sup', 'kg en plus', 'excedent', 'excedent kg', 'excedent au kg', 'prix kg', 'kg'],
    ];

    public static function template(): string
    {
        return "\u{FEFF}Ville;Délai;Pli;Colis;Kg supplémentaire\nBouaké;24h;3000;4000;500\nBouaké;48h;2500;3500;500\nBouaké;72h;2000;3000;500\nYamoussoukro;24h;3000;4000;500\n";
    }

    /**
     * @return array{rows: int, created: int, updated: int, zones_created: list<string>, errors: list<string>}
     */
    public function import(Company $company, string $path, string $extension, bool $dryRun = false, ?int $merchantId = null): array
    {
        $rows = $this->read($path, $extension);
        $errors = [];
        $valid = [];

        foreach ($rows as $line => $row) {
            $city = trim((string) ($row['city'] ?? ''));
            $delay = $this->delay($row['delay'] ?? null);
            $prices = array_map(fn ($f) => $this->amount($row[$f] ?? null), ['pli' => 'pli', 'colis' => 'colis', 'extra_kg' => 'extra_kg']);

            $problem = match (true) {
                $city === '' => 'ville manquante',
                ClientPricing::cityKey($city) === ClientPricing::cityKey('Abidjan') => 'Abidjan n\'est pas une ville de l\'intérieur',
                $delay === null => 'délai à indiquer : 24h, 48h ou 72h',
                in_array(false, $prices, true) => 'prix illisible (nombre entier en francs)',
                $prices['pli'] === null && $prices['colis'] === null => 'prix du pli et du colis manquants',
                default => null,
            };
            if ($problem) {
                $errors[] = "Ligne {$line} : {$problem}.";

                continue;
            }

            $valid[ClientPricing::cityKey($city).'|'.$delay->value] = ['city' => Str::title(mb_strtolower($city)), 'delay' => $delay, ...$prices];
        }

        // Grille commune, ou prix négociés d'un client
        $existing = InteriorRate::forCompany($company->id)->grid($merchantId)->get()->keyBy(fn (InteriorRate $r) => ClientPricing::cityKey($r->city).'|'.$r->delivery_delay->value);
        $zoneCities = Zone::forCompany($company->id)->pluck('city')->map(fn ($c) => ClientPricing::cityKey($c))->unique()->all();
        $newCities = collect($valid)->pluck('city')->unique(fn ($c) => ClientPricing::cityKey($c))
            ->reject(fn ($c) => in_array(ClientPricing::cityKey($c), $zoneCities, true))->values()->all();

        $report = [
            'rows' => count($rows),
            'created' => count(array_diff_key($valid, $existing->all())),
            'updated' => count(array_intersect_key($valid, $existing->all())),
            'zones_created' => $newCities,
            'errors' => $errors,
        ];

        if ($dryRun) {
            return $report;
        }

        DB::transaction(function () use ($company, $valid, $existing, $newCities, $merchantId) {
            foreach ($valid as $key => $rate) {
                $model = $existing[$key] ?? new InteriorRate(['company_id' => $company->id, 'merchant_id' => $merchantId, 'city' => $rate['city'], 'delivery_delay' => $rate['delay']]);
                $model->fill([
                    'pli_price' => $rate['pli'] ?? $rate['colis'],
                    'colis_price' => $rate['colis'] ?? $rate['pli'],
                    'extra_kg_price' => $rate['extra_kg'] ?? 0,
                ])->save();
            }

            foreach ($newCities as $city) {
                self::ensureZone($company->id, $city);
            }
        });

        return $report;
    }

    /**
     * Zone de livraison de la ville (créée au besoin) : choisie sur la course pour livrer à l'intérieur.
     */
    public static function ensureZone(int $companyId, string $city): Zone
    {
        $existing = Zone::forCompany($companyId)->get()->first(fn (Zone $z) => ClientPricing::cityKey($z->city) === ClientPricing::cityKey($city));

        return $existing ?? Zone::create([
            'company_id' => $companyId,
            'name' => $city,
            'city' => $city,
            'sort_order' => (int) Zone::forCompany($companyId)->max('sort_order') + 1,
        ]);
    }

    private function delay(mixed $value): ?DeliveryDelay
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return DeliveryDelay::tryFrom($digits.'h');
    }

    /** Montant en francs : null si vide, false si illisible */
    private function amount(mixed $value): int|false|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? (int) round($value) : false;
        }
        $clean = preg_replace('/[\s\x{00A0}\x{202F}.]|F(CFA)?$/iu', '', trim((string) $value));

        return preg_match('/^\d+$/', $clean) ? (int) $clean : false;
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
                        if (! in_array('city', $header, true) || ! in_array('delay', $header, true)) {
                            throw new BusinessRuleException('Colonnes attendues : Ville, Délai, Pli, Colis, Kg supplémentaire (voir le modèle).', 'file');
                        }

                        continue;
                    }
                    if (count(array_filter($values, fn ($v) => trim((string) ($v instanceof DateTimeInterface ? 'x' : $v)) !== '')) === 0) {
                        continue;
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        throw new BusinessRuleException('Au plus '.self::MAX_ROWS.' lignes par fichier.', 'file');
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
        $header = [];
        foreach ($values as $index => $value) {
            $name = trim(preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower(trim((string) $value), 'UTF-8'))));
            foreach (self::COLUMNS as $field => $names) {
                if (in_array($name, $names, true) && ! in_array($field, $header, true)) {
                    $header[$index] = $field;
                }
            }
        }

        return $header;
    }

    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        fclose($handle);

        return collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
    }
}
