<?php

namespace App\Services\Destinations;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Destination;
use Illuminate\Support\Facades\DB;

/**
 * Import de l'annuaire : export SQL de l'ancienne base (table base_clients) ou fichier CSV (nom, ville,
 * commune, adresse, contact, secteur, whatsapp, email). Les numéros douteux restent tels quels, « à
 * vérifier » ; les doublons (même nom, même commune) sont regroupés ; un second import ne reprend pas les
 * fiches déjà importées. En essai, rien n'est enregistré : le rapport dit ce qui serait fait.
 */
class DestinationImporter
{
    private const COLUMNS = ['id', 'nom', 'ville', 'commune', 'adresse', 'contact', 'created_at', 'updated_at', 'secteur', 'whatsapp', 'email'];

    public function __construct(private readonly DestinationCleaner $cleaner) {}

    /**
     * @return array<string, mixed> rapport
     */
    public function import(Company $company, string $content, string $extension, bool $dryRun = true, ?int $userId = null): array
    {
        $rows = $extension === 'sql' ? $this->sqlRows($content) : $this->csvRows($content);
        if ($rows === []) {
            throw new BusinessRuleException('Aucune destination trouvée dans ce fichier (export SQL de base_clients, ou CSV avec une colonne « nom »).', 'file');
        }
        $this->cleaner->useZones($company->id);
        $known = Destination::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('legacy_id')->pluck('legacy_id')->flip();
        // Déjà dans l'annuaire (même nom, même commune) : pas de doublon
        $existing = Destination::withoutGlobalScopes()->where('company_id', $company->id)->get(['name', 'zone_id', 'district'])
            ->mapWithKeys(fn (Destination $d) => [Destination::normalize($d->name).'|'.($d->zone_id ?? Destination::normalize($d->district)) => true]);

        $report = ['rows' => count($rows), 'created' => 0, 'merged' => 0, 'already' => 0, 'skipped' => 0,
            'phones_ok' => 0, 'phones_check' => 0, 'no_phone' => 0, 'zone_found' => 0, 'zone_missing' => 0,
            'unknown_communes' => [], 'cities' => [], 'samples' => []];
        $byKey = [];
        foreach ($rows as $row) {
            $nameRaw = trim((string) ($row['nom'] ?? ''));
            if ($nameRaw === '') {
                $report['skipped']++;

                continue;
            }
            if (isset($row['id']) && is_numeric($row['id']) && $known->has((int) $row['id'])) {
                $report['already']++;

                continue;
            }
            [$name, $acronym] = DestinationCleaner::name($nameRaw);
            $city = DestinationCleaner::city($row['ville'] ?? null) ?? (filled($row['commune'] ?? null) ? 'Abidjan' : null);
            [$zone, $district] = $this->cleaner->zone($row['commune'] ?? null, $city, $row['adresse'] ?? null);
            $rawPhones = trim(implode(' / ', array_filter([trim((string) ($row['contact'] ?? '')), trim((string) ($row['whatsapp'] ?? ''))])));
            $phones = DestinationPhones::parse($rawPhones);
            $key = Destination::normalize($name).'|'.($zone?->id ?? Destination::normalize($row['commune'] ?? ''));
            if ($existing->has($key)) {
                $report['already']++;

                continue;
            }
            if (isset($byKey[$key])) {
                // Doublon : numéros et informations manquantes ajoutés à la première fiche
                $first = &$byKey[$key];
                $first['valid'] = array_values(array_unique([...$first['valid'], ...$phones['valid']]));
                $first['doubtful'] = array_values(array_unique([...$first['doubtful'], ...$phones['doubtful']]));
                $first['raw'] = trim(implode(' / ', array_unique(array_filter([$first['raw'], $rawPhones]))));
                foreach (['address', 'email', 'sector', 'district'] as $field) {
                    $first['attributes'][$field] ??= null;
                }
                $first['attributes']['address'] ??= $this->text($row['adresse'] ?? null, 2000);
                $first['attributes']['email'] ??= $this->email($row['email'] ?? null);
                $first['attributes']['sector'] ??= $this->text($row['secteur'] ?? null, 120);
                unset($first);
                $report['merged']++;

                continue;
            }

            $byKey[$key] = [
                'attributes' => [
                    'company_id' => $company->id,
                    'name' => mb_substr($name, 0, 255),
                    'acronym' => $acronym ? mb_substr($acronym, 0, 60) : null,
                    'city' => $city ? mb_substr($city, 0, 80) : null,
                    'zone_id' => $zone?->id,
                    'district' => $district ? mb_substr($district, 0, 120) : null,
                    'address' => $this->text($row['adresse'] ?? null, 2000),
                    'email' => $this->email($row['email'] ?? null),
                    'sector' => $this->text($row['secteur'] ?? null, 120),
                    'legacy_id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null,
                ],
                'valid' => $phones['valid'],
                'doubtful' => $phones['doubtful'],
                'raw' => $rawPhones,
                'commune' => $row['commune'] ?? null,
                'ville' => $row['ville'] ?? null,
            ];
        }

        $records = [];
        foreach ($byKey as $item) {
            $a = $item['attributes'];
            $valid = array_slice($item['valid'], 0, 3);
            // Ancienne forme d'un numéro déjà là au bon format (fiches regroupées comprises) : rien à vérifier
            $item['doubtful'] = array_values(array_filter($item['doubtful'], fn ($d) => ! in_array(DestinationPhones::suggestion($d), $item['valid'], true)));
            $check = $item['doubtful'] !== [];
            $records[] = [
                ...$a,
                'phone' => $valid[0] ?? null, 'phone2' => $valid[1] ?? null, 'phone3' => $valid[2] ?? null,
                // Numéros d'origine gardés quand l'un d'eux est douteux (ou en trop)
                'phones_raw' => $check || count($item['valid']) > 3 ? mb_substr($item['raw'], 0, 255) : null,
                'phone_check' => $check,
                'source' => 'import', 'status' => 'active', 'created_by' => $userId,
            ];
            $report[$check ? 'phones_check' : ($valid ? 'phones_ok' : 'no_phone')]++;
            if ($a['zone_id']) {
                $report['zone_found']++;
            } else {
                $report['zone_missing']++;
                $commune = trim(mb_strtoupper((string) $item['commune'])) ?: '(vide)';
                $report['unknown_communes'][$commune] = ($report['unknown_communes'][$commune] ?? 0) + 1;
            }
            $ville = trim(mb_strtoupper((string) $item['ville']));
            if ($ville !== '' && $a['city'] !== null && $ville !== mb_strtoupper($a['city'])) {
                $report['cities']["{$ville} → {$a['city']}"] = ($report['cities']["{$ville} → {$a['city']}"] ?? 0) + 1;
            }
            if (count($report['samples']) < 8 && ($check || $a['acronym'])) {
                $report['samples'][] = ['name' => $a['acronym'] ? "{$a['acronym']} · {$a['name']}" : $a['name'], 'phones' => array_map(fn ($p) => substr($p, 4), $valid),
                    'raw' => $item['raw'], 'check' => $check, 'zone_id' => $a['zone_id'], 'district' => $a['district'],
                    'suggestions' => array_values(array_filter(array_map(fn ($d) => DestinationPhones::suggestion($d), $item['doubtful'])))];
            }
        }
        $report['created'] = count($records);
        arsort($report['unknown_communes']);
        arsort($report['cities']);
        $report['unknown_communes'] = array_slice($report['unknown_communes'], 0, 20, true);
        $report['cities'] = array_slice($report['cities'], 0, 15, true);
        $report['dry_run'] = $dryRun;

        if (! $dryRun) {
            DB::transaction(function () use ($records) {
                $now = now();
                foreach (array_chunk($records, 500) as $chunk) {
                    Destination::withoutGlobalScopes()->insert(array_map(function (array $r) use ($now) {
                        $r['search'] = (new Destination($r))->searchText();

                        return [...$r, 'created_at' => $now, 'updated_at' => $now];
                    }, $chunk));
                }
            });
        }

        return $report;
    }

    /**
     * Lignes des INSERT INTO `base_clients` d'un export phpMyAdmin.
     *
     * @return list<array<string, ?string>>
     */
    public function sqlRows(string $sql): array
    {
        if (! mb_check_encoding($sql, 'UTF-8')) {
            $sql = mb_convert_encoding($sql, 'UTF-8', 'ISO-8859-1');
        }
        $rows = [];
        $offset = 0;
        while (preg_match('/INSERT INTO `?base_clients`?\s*\(([^)]*)\)\s*VALUES\s*/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $columns = array_map(fn ($c) => trim($c, " `\t\n\r"), explode(',', $m[1][0]));
            $i = $m[0][1] + strlen($m[0][0]);
            $length = strlen($sql);
            while ($i < $length && $sql[$i] === '(') {
                $i++;
                $values = [];
                $current = '';
                $quoted = false;
                $wasQuoted = false;
                while ($i < $length) {
                    $c = $sql[$i];
                    if ($quoted) {
                        if ($c === '\\') {
                            $next = $sql[$i + 1] ?? '';
                            $current .= match ($next) {
                                'n' => "\n", 'r' => "\r", 't' => "\t", '0' => '', default => $next
                            };
                            $i += 2;

                            continue;
                        }
                        if ($c === "'") {
                            if (($sql[$i + 1] ?? '') === "'") {
                                $current .= "'";
                                $i += 2;

                                continue;
                            }
                            $quoted = false;
                            $i++;

                            continue;
                        }
                        $current .= $c;
                        $i++;

                        continue;
                    }
                    if ($c === "'") {
                        $quoted = $wasQuoted = true;
                    } elseif ($c === ',' || $c === ')') {
                        $values[] = ! $wasQuoted && strtoupper(trim($current)) === 'NULL' ? null : ($wasQuoted ? $current : trim($current));
                        $current = '';
                        $wasQuoted = false;
                        if ($c === ')') {
                            $i++;
                            break;
                        }
                    } else {
                        $current .= $c;
                    }
                    $i++;
                }
                if (count($values) === count($columns)) {
                    $rows[] = array_combine($columns, $values);
                }
                while ($i < $length && in_array($sql[$i], [',', ' ', "\n", "\r", "\t"], true)) {
                    $i++;
                }
            }
            $offset = $i;
        }

        return $rows;
    }

    /**
     * Lignes d'un CSV (« ; » ou « , ») dont la première ligne nomme les colonnes.
     *
     * @return list<array<string, ?string>>
     */
    public function csvRows(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'ISO-8859-1');
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $first = array_shift($lines) ?? '';
        $separator = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $headers = array_map(fn ($h) => match ($key = Destination::normalize($h)) {
            'nom', 'name', 'entreprise', 'raison sociale' => 'nom',
            'telephone', 'tel', 'contact', 'contacts', 'phone' => 'contact',
            'quartier', 'commune', 'zone' => 'commune',
            default => $key,
        }, str_getcsv($first, $separator, '"', ''));
        if (! in_array('nom', $headers, true)) {
            return [];
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $separator, '"', '');
            $row = [];
            foreach ($headers as $i => $header) {
                if (in_array($header, self::COLUMNS, true)) {
                    $row[$header] = isset($values[$i]) && trim($values[$i]) !== '' ? trim($values[$i]) : null;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function text(?string $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function email(?string $value): ?string
    {
        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr($value, 0, 190) : null;
    }
}
