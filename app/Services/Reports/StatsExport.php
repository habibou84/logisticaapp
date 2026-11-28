<?php

namespace App\Services\Reports;

use App\Enums\Activity;
use App\Models\Company;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export des statistiques d'une période, en Excel (une feuille par rubrique), CSV ou PDF : au choix parmi synthèse, mois par mois,
 * rentabilité (clients, zones, livreurs, contrats), délais, échecs, clients en baisse, paiements. Les onglets avec des
 * montants ne sont mis que pour qui voit la caisse. Sert au téléchargement et à l'envoi par e-mail.
 */
class StatsExport
{
    public function __construct(
        private readonly Performance $performance,
        private readonly Profitability $profitability,
        private readonly ServiceQuality $quality,
        private readonly ClientInsights $clients,
    ) {}

    /** Rubriques exportables : clé => [libellé, contient des montants ?] ; dans l'ordre du fichier */
    public const SECTIONS = [
        'summary' => ['Synthèse', false],
        'months' => ['Mois par mois', false],
        'days' => ['Jour par jour', false],
        'clients' => ['Rentabilité clients', true],
        'zones' => ['Rentabilité zones', true],
        'couriers' => ['Livreurs', true],
        'contracts' => ['Contrats', true],
        'delays_clients' => ['Délais par client', false],
        'delays_zones' => ['Délais par zone', false],
        'failures' => ['Échecs', false],
        'failures_couriers' => ['Échecs par livreur', false],
        'decline' => ['Clients en baisse', false],
        'payments' => ['Paiements', true],
        'orders' => ['Liste des courses', false],
    ];

    /** Rubriques de l'export complet d'origine (téléchargement simple, point par e-mail) */
    public const DEFAULT_SECTIONS = ['summary', 'months', 'clients', 'zones', 'couriers', 'contracts', 'delays_clients', 'delays_zones',
        'failures', 'failures_couriers', 'decline', 'payments'];

    public const FORMATS = ['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'];

    /** Liste des courses : lignes au plus */
    private const MAX_ORDERS = 20000;

    /** PDF : lignes au plus par tableau (au-delà, le fichier devient illisible et trop lourd) */
    private const PDF_ROWS = 150;

    /**
     * Export complet d'origine : construit le fichier Excel et renvoie son chemin (fichier temporaire, à supprimer
     * après l'envoi).
     */
    public function xlsx(Company $company, CarbonImmutable $from, CarbonImmutable $to, bool $withAmounts, ?string $activity = null): string
    {
        return $this->file($company, $from, $to, ['amounts' => $withAmounts, 'activity' => $activity]);
    }

    /**
     * Export au choix : rubriques, activité, format, montants masqués, noms des livreurs anonymisés.
     * Renvoie le chemin d'un fichier temporaire, à supprimer après l'envoi.
     *
     * @param  array{sections?: list<string>, format?: string, amounts?: bool, activity?: string|null, anonymize?: bool}  $options
     */
    public function file(Company $company, CarbonImmutable $from, CarbonImmutable $to, array $options = []): string
    {
        $format = isset(self::FORMATS[$options['format'] ?? '']) ? $options['format'] : 'xlsx';
        $tables = $this->tables($company, $from, $to, $options);
        $path = tempnam(sys_get_temp_dir(), 'stats').'.'.$format;

        match ($format) {
            'csv' => $this->writeCsv($path, $tables),
            'pdf' => $this->writePdf($path, $tables, $company, $from, $to, $options['activity'] ?? null),
            default => $this->writeXlsx($path, $tables),
        };

        return $path;
    }

    /**
     * Les tableaux demandés, dans l'ordre des rubriques. Les rubriques à montants sont laissées de côté sans le
     * droit caisse ou quand les montants sont masqués.
     *
     * @param  array<string, mixed>  $options
     * @return list<array{key: string, name: string, header: list<string>, rows: list<list<mixed>>}>
     */
    public function tables(Company $company, CarbonImmutable $from, CarbonImmutable $to, array $options = []): array
    {
        $withAmounts = (bool) ($options['amounts'] ?? true);
        $activity = $options['activity'] ?? null;
        $wanted = array_values(array_intersect(array_keys(self::SECTIONS), $options['sections'] ?? self::DEFAULT_SECTIONS))
            ?: self::DEFAULT_SECTIONS;
        $wanted = array_values(array_filter($wanted, fn (string $k) => $withAmounts || ! self::SECTIONS[$k][1]));
        $has = fn (string ...$keys) => array_intersect($keys, $wanted) !== [];
        // Livreurs anonymisés : « Livreur 1 », « Livreur 2 »…, le même numéro dans tout le fichier
        $aliases = [];
        $courier = function (?string $name) use (&$aliases, $options) {
            if (! ($options['anonymize'] ?? false) || $name === null || $name === '') {
                return $name;
            }

            return $aliases[$name] ??= 'Livreur '.(count($aliases) + 1);
        };

        $perf = $has('months') ? $this->performance->build($company, $withAmounts, $activity) : null;
        $quality = $has('summary', 'delays_clients', 'delays_zones', 'failures', 'failures_couriers') ? $this->quality->build($company, $from, $to, $activity) : null;
        $profit = $withAmounts && $has('summary', 'clients', 'zones', 'couriers', 'contracts') ? $this->profitability->build($company, $from, $to, $activity) : null;
        $clients = $has('decline', 'payments') ? $this->clients->build($company, $from, $to, $withAmounts, $activity) : null;

        $build = [
            'summary' => function () use ($from, $to, $quality, $profit) {
                $rows = [
                    ['Période', $from->format('d/m/Y').' au '.$to->format('d/m/Y')],
                    ['Livrées à l\'heure (%)', $quality['delays']['rate']],
                    ['Livrées', $quality['delays']['total']],
                    ['Échecs de livraison', $quality['failures']['total']],
                ];
                if ($profit) {
                    $t = $profit['totals'];
                    array_push($rows, ['Revenus', $t['revenue']], ['Coûts directs', $t['direct_costs']], ['Frais fixes', $t['fixed_costs']],
                        ['Marge nette', $t['margin']], ['Taux de marge (%)', $t['margin_rate']], ['Coût par course livrée', $t['cost_per_delivery']],
                        ['Livraisons par livreur et par jour', $t['deliveries_per_courier_day']]);
                }

                return [['Indicateur', 'Valeur'], $rows];
            },
            'months' => fn () => [
                ['Mois', 'Créées', 'Livrées', 'Échecs', 'Réussite (%)', ...($withAmounts ? ['Chiffre d\'affaires', 'Coûts', 'Marge'] : [])],
                array_map(fn ($m) => [$m['label'], $m['created'], $m['delivered'], $m['failed'], $m['success_rate'], ...($withAmounts ? [$m['revenue'], $m['costs'], $m['margin']] : [])], $perf['months']),
            ],
            'days' => function () use ($company, $from, $to, $activity, $withAmounts) {
                $series = app(Statistics::class)->for($company)->forActivity($activity)->overview($from, $to, $withAmounts)['series'];

                return [
                    ['Jour', 'Créées', 'Livrées', ...($withAmounts ? ['Frais de livraison'] : [])],
                    array_map(fn ($d) => [CarbonImmutable::parse($d['date'])->format('d/m/Y'), $d['created'], $d['delivered'], ...($withAmounts ? [$d['revenue']] : [])], $series),
                ];
            },
            'clients' => fn () => [
                ['Client', 'Livrées', 'Revenus', 'Coûts directs', 'Part frais fixes', 'Marge', 'Taux (%)', 'Par course', 'Verdict'],
                array_map(fn ($c) => [$c['name'], $c['courses'], $c['revenue'], $c['direct_costs'], $c['fixed_share'], $c['margin'], $c['margin_rate'], $c['margin_per_course'],
                    ['profitable' => 'Rentable', 'renegotiate' => 'À renégocier', 'loss' => 'À perte'][$c['verdict']] ?? ''], $profit['clients']),
            ],
            'zones' => fn () => [
                ['Zone', 'Livrées', 'Tarif moyen', 'Coût par livraison', 'Revenus', 'Marge', 'Taux (%)'],
                array_map(fn ($z) => [$z['name'], $z['delivered'], $z['average_fee'], $z['cost_per_delivery'], $z['revenue'], $z['margin'], $z['margin_rate']], $profit['zones']),
            ],
            'couriers' => fn () => [
                ['Livreur', 'Missions', 'Livraisons', 'Jours', 'Livraisons par jour', 'Paie', 'Véhicule', 'Coût par mission', 'Revenus livrés', 'Marge'],
                array_map(fn ($c) => [$courier($c['name']), $c['missions'], $c['deliveries'], $c['days_worked'], $c['per_day'], $c['pay'], $c['vehicle'], $c['cost_per_mission'], $c['revenue'], $c['margin']], $profit['couriers']),
            ],
            'contracts' => fn () => [
                ['Client', 'Contrat', 'Forfait par mois', 'Inclus', 'Utilisé par mois', 'Utilisation (%)', 'Revenus', 'Coût MAD', 'Marge MAD'],
                array_map(fn ($c) => [$c['merchant'], $c['label'], $c['monthly_fee'], $c['included'], $c['used_per_month'], $c['usage'], $c['revenue'], $c['mad_cost'], $c['mad_margin']], $profit['contracts']),
            ],
            'delays_clients' => fn () => [
                ['Client', 'Livrées', 'À l\'heure', 'En retard', 'Taux (%)'],
                array_map(fn ($r) => [$r['label'], $r['total'], $r['on_time'], $r['late'], $r['rate']], $quality['delays']['by_client']),
            ],
            'delays_zones' => fn () => [
                ['Zone', 'Livrées', 'À l\'heure', 'En retard', 'Taux (%)'],
                array_map(fn ($r) => [$r['label'], $r['total'], $r['on_time'], $r['late'], $r['rate']], $quality['delays']['by_zone']),
            ],
            'failures' => fn () => [['Motif', 'Nombre'], array_map(fn ($r) => [$r['label'], $r['count']], $quality['failures']['reasons'])],
            'failures_couriers' => fn () => [
                ['Livreur', 'Échecs', 'Livrées', 'Taux d\'échec (%)', 'Motif le plus fréquent'],
                array_map(fn ($r) => [$courier($r['label']), $r['failures'], $r['delivered'], $r['failure_rate'], $r['top_reason']], $quality['failures']['by_courier']),
            ],
            'decline' => fn () => [
                ['Client', 'Contact', 'Téléphone', 'Moyenne par mois', '30 derniers jours', 'Évolution (%)'],
                array_map(fn ($r) => [$r['name'], $r['contact_name'], $r['phone'], $r['average'], $r['recent'], $r['change']], $clients['decline']),
            ],
            'payments' => fn () => $clients['payments'] ? [
                ['Client', 'Factures', 'Délai moyen (jours)', 'Reste à payer', 'Dont en retard'],
                array_map(fn ($r) => [$r['name'], $r['invoices'], $r['average_days'], $r['balance'], $r['overdue']], $clients['payments']['clients']),
            ] : null,
            'orders' => fn () => $this->orders($company, $from, $to, $activity, $withAmounts, $courier),
        ];

        $tables = [];
        foreach ($wanted as $key) {
            $table = $build[$key]();
            if ($table !== null) {
                $tables[] = ['key' => $key, 'name' => self::SECTIONS[$key][0], 'header' => $table[0],
                    'rows' => array_map(fn ($row) => array_map(fn ($v) => is_bool($v) ? ($v ? 'oui' : 'non') : $v, array_values($row)), $table[1])];
            }
        }

        return $tables;
    }

    /**
     * Une ligne par course créée sur la période (les plus récentes d'abord).
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function orders(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?string $activity, bool $withAmounts, callable $courier): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $range = [CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay()->utc(), CarbonImmutable::parse($to->toDateString(), $tz)->endOfDay()->utc()];
        $local = fn ($moment) => $moment ? CarbonImmutable::parse($moment)->setTimezone($tz)->format('d/m/Y H:i') : null;
        $labels = collect(Activity::cases())->mapWithKeys(fn (Activity $a) => [$a->value => $a->label()]);
        $rows = Order::forCompany($company->id)->when($activity, fn ($q) => $q->where('activity', $activity))->whereBetween('created_at', $range)
            ->with(['merchant:id,business_name', 'deliveryZone:id,name', 'deliveryCourier.user:id,name'])->orderByDesc('created_at')->limit(self::MAX_ORDERS)
            ->get(['id', 'tracking_code', 'merchant_id', 'delivery_zone_id', 'delivery_courier_id', 'activity', 'status', 'attempts_count', 'created_at', 'delivered_at',
                'delivery_fee', 'surcharges_total', 'billing_amount'])
            ->map(function (Order $o) use ($local, $labels, $withAmounts, $courier) {
                $a = $o->activity instanceof \BackedEnum ? $o->activity->value : (string) $o->activity;

                return [$o->tracking_code, $local($o->created_at), $o->merchant?->business_name, $labels[$a] ?? $a, $o->deliveryZone?->name, $o->status->label(),
                    $courier($o->deliveryCourier?->user?->name), $o->attempts_count, $local($o->delivered_at),
                    ...($withAmounts ? [$a === 'ecommerce' ? $o->delivery_fee + $o->surcharges_total : (int) $o->billing_amount] : [])];
            })->all();

        return [['Code', 'Créée le', 'Client', 'Activité', 'Zone', 'État', 'Livreur', 'Échecs', 'Livrée le', ...($withAmounts ? ['Montant facturé'] : [])], $rows];
    }

    /** @param list<array{key: string, name: string, header: list<string>, rows: list<list<mixed>>}> $tables */
    private function writeXlsx(string $path, array $tables): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        foreach ($tables as $i => $table) {
            $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr($table['name'], 0, 31));
            $writer->addRow(Row::fromValuesWithStyle($table['header'], $bold));
            foreach ($table['rows'] as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
        $writer->close();
    }

    /**
     * Un seul fichier CSV lisible par Excel (séparateur « ; », accents conservés) : les rubriques l'une sous l'autre,
     * chacune précédée de son nom.
     *
     * @param  list<array{key: string, name: string, header: list<string>, rows: list<list<mixed>>}>  $tables
     */
    private function writeCsv(string $path, array $tables): void
    {
        $out = fopen($path, 'w');
        fwrite($out, "\u{FEFF}");
        // Un texte qui commence par = + - @ serait lu comme une formule par Excel : on le neutralise
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
        foreach ($tables as $i => $table) {
            if (count($tables) > 1) {
                if ($i > 0) {
                    fputcsv($out, [], ';', '"', '');
                }
                fputcsv($out, [$table['name']], ';', '"', '');
            }
            fputcsv($out, $table['header'], ';', '"', '');
            foreach ($table['rows'] as $row) {
                fputcsv($out, array_map($safe, $row), ';', '"', '');
            }
        }
        fclose($out);
    }

    /**
     * PDF mis en page (A4 paysage) : un tableau par rubrique, limité à ses premières lignes.
     *
     * @param  list<array{key: string, name: string, header: list<string>, rows: list<list<mixed>>}>  $tables
     */
    private function writePdf(string $path, array $tables, Company $company, CarbonImmutable $from, CarbonImmutable $to, ?string $activity): void
    {
        $html = view('exports.stats-pdf', [
            'company' => $company,
            'period' => $from->format('d/m/Y').' au '.$to->format('d/m/Y'),
            'activity' => $activity ? Activity::tryFrom($activity)?->label() : null,
            'tables' => array_map(fn (array $t) => [...$t, 'total' => count($t['rows']), 'rows' => array_slice($t['rows'], 0, self::PDF_ROWS)], $tables),
            'limit' => self::PDF_ROWS,
        ])->render();
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        file_put_contents($path, $pdf->output());
    }

    /**
     * Chiffres clés de la période pour un e-mail ou un rapport (sans montants si pas de droit caisse).
     *
     * @return array<string, mixed>
     */
    public function summary(Company $company, CarbonImmutable $from, CarbonImmutable $to, bool $withAmounts = true): array
    {
        $quality = $this->quality->build($company, $from, $to);
        $profit = $withAmounts ? $this->profitability->build($company, $from, $to) : null;

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'delivered' => $quality['delays']['total'],
            'on_time_rate' => $quality['delays']['rate'],
            'failures' => $quality['failures']['total'],
            'top_reasons' => array_slice($quality['failures']['reasons'], 0, 3),
            'money' => $profit ? collect($profit['totals'])->only(['revenue', 'direct_costs', 'fixed_costs', 'margin', 'margin_rate', 'cost_per_delivery'])->all() : null,
            'losing_clients' => $profit ? collect($profit['clients'])->where('verdict', 'loss')->take(5)->values()->all() : [],
        ];
    }
}
