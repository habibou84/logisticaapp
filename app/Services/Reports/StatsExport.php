<?php

namespace App\Services\Reports;

use App\Models\Company;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export Excel complet des statistiques d'une période (plusieurs onglets, en-têtes en gras) : synthèse, mois par mois,
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

    /**
     * Construit le fichier et renvoie son chemin (fichier temporaire, à supprimer après l'envoi).
     */
    public function xlsx(Company $company, CarbonImmutable $from, CarbonImmutable $to, bool $withAmounts, ?string $activity = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stats').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $first = true;
        $sheet = function (string $name, array $header, iterable $rows) use ($writer, $bold, &$first) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName(mb_substr($name, 0, 31));
            $writer->addRow(Row::fromValuesWithStyle($header, $bold));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => is_bool($v) ? ($v ? 'oui' : 'non') : $v, array_values($row))));
            }
        };

        $perf = $this->performance->build($company, $withAmounts, $activity);
        $quality = $this->quality->build($company, $from, $to, $activity);
        $profit = $withAmounts ? $this->profitability->build($company, $from, $to, $activity) : null;
        $clients = $this->clients->build($company, $from, $to, $withAmounts, $activity);

        $synthese = [
            ['Période', $from->format('d/m/Y').' au '.$to->format('d/m/Y')],
            ['Livrées à l\'heure (%)', $quality['delays']['rate']],
            ['Livrées', $quality['delays']['total']],
            ['Échecs de livraison', $quality['failures']['total']],
        ];
        if ($profit) {
            $t = $profit['totals'];
            array_push($synthese, ['Revenus', $t['revenue']], ['Coûts directs', $t['direct_costs']], ['Frais fixes', $t['fixed_costs']],
                ['Marge nette', $t['margin']], ['Taux de marge (%)', $t['margin_rate']], ['Coût par course livrée', $t['cost_per_delivery']],
                ['Livraisons par livreur et par jour', $t['deliveries_per_courier_day']]);
        }
        $sheet('Synthèse', ['Indicateur', 'Valeur'], $synthese);
        $sheet('Mois par mois', ['Mois', 'Créées', 'Livrées', 'Échecs', 'Réussite (%)', ...($withAmounts ? ['Chiffre d\'affaires', 'Coûts', 'Marge'] : [])],
            array_map(fn ($m) => [$m['label'], $m['created'], $m['delivered'], $m['failed'], $m['success_rate'], ...($withAmounts ? [$m['revenue'], $m['costs'], $m['margin']] : [])], $perf['months']));

        if ($profit) {
            $verdicts = ['profitable' => 'Rentable', 'renegotiate' => 'À renégocier', 'loss' => 'À perte'];
            $sheet('Rentabilité clients', ['Client', 'Livrées', 'Revenus', 'Coûts directs', 'Part frais fixes', 'Marge', 'Taux (%)', 'Par course', 'Verdict'],
                array_map(fn ($c) => [$c['name'], $c['courses'], $c['revenue'], $c['direct_costs'], $c['fixed_share'], $c['margin'], $c['margin_rate'], $c['margin_per_course'], $verdicts[$c['verdict']] ?? ''], $profit['clients']));
            $sheet('Rentabilité zones', ['Zone', 'Livrées', 'Tarif moyen', 'Coût par livraison', 'Revenus', 'Marge', 'Taux (%)'],
                array_map(fn ($z) => [$z['name'], $z['delivered'], $z['average_fee'], $z['cost_per_delivery'], $z['revenue'], $z['margin'], $z['margin_rate']], $profit['zones']));
            $sheet('Livreurs', ['Livreur', 'Missions', 'Livraisons', 'Jours', 'Livraisons par jour', 'Paie', 'Véhicule', 'Coût par mission', 'Revenus livrés', 'Marge'],
                array_map(fn ($c) => [$c['name'], $c['missions'], $c['deliveries'], $c['days_worked'], $c['per_day'], $c['pay'], $c['vehicle'], $c['cost_per_mission'], $c['revenue'], $c['margin']], $profit['couriers']));
            $sheet('Contrats', ['Client', 'Contrat', 'Forfait par mois', 'Inclus', 'Utilisé par mois', 'Utilisation (%)', 'Revenus', 'Coût MAD', 'Marge MAD'],
                array_map(fn ($c) => [$c['merchant'], $c['label'], $c['monthly_fee'], $c['included'], $c['used_per_month'], $c['usage'], $c['revenue'], $c['mad_cost'], $c['mad_margin']], $profit['contracts']));
        }
        $sheet('Délais par client', ['Client', 'Livrées', 'À l\'heure', 'En retard', 'Taux (%)'],
            array_map(fn ($r) => [$r['label'], $r['total'], $r['on_time'], $r['late'], $r['rate']], $quality['delays']['by_client']));
        $sheet('Délais par zone', ['Zone', 'Livrées', 'À l\'heure', 'En retard', 'Taux (%)'],
            array_map(fn ($r) => [$r['label'], $r['total'], $r['on_time'], $r['late'], $r['rate']], $quality['delays']['by_zone']));
        $sheet('Échecs', ['Motif', 'Nombre'], array_map(fn ($r) => [$r['label'], $r['count']], $quality['failures']['reasons']));
        $sheet('Échecs par livreur', ['Livreur', 'Échecs', 'Livrées', 'Taux d\'échec (%)', 'Motif le plus fréquent'],
            array_map(fn ($r) => [$r['label'], $r['failures'], $r['delivered'], $r['failure_rate'], $r['top_reason']], $quality['failures']['by_courier']));
        $sheet('Clients en baisse', ['Client', 'Contact', 'Téléphone', 'Moyenne par mois', '30 derniers jours', 'Évolution (%)'],
            array_map(fn ($r) => [$r['name'], $r['contact_name'], $r['phone'], $r['average'], $r['recent'], $r['change']], $clients['decline']));
        if ($clients['payments']) {
            $sheet('Paiements', ['Client', 'Factures', 'Délai moyen (jours)', 'Reste à payer', 'Dont en retard'],
                array_map(fn ($r) => [$r['name'], $r['invoices'], $r['average_days'], $r['balance'], $r['overdue']], $clients['payments']['clients']));
        }
        $writer->close();

        return $path;
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
