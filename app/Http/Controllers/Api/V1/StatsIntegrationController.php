<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Reports\Performance;
use App\Services\Reports\Profitability;
use App\Services\Reports\ServiceQuality;
use App\Services\Reports\StatsExport;
use App\Services\Reports\StatsMailer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intégrations des statistiques sans IA : export Excel complet, lien Google Sheets (données relues par
 * =IMPORTDATA), point par e-mail (essai).
 */
class StatsIntegrationController extends Controller
{
    /** Jeux de données du lien Google Sheets */
    public const FEEDS = [
        'mois' => 'Mois par mois (13 mois)',
        'clients' => 'Rentabilité par client',
        'zones' => 'Rentabilité par zone',
        'livreurs' => 'Coût et rendement des livreurs',
        'delais' => 'Respect des délais par client',
        'echecs' => 'Motifs d\'échec',
        'releves' => 'Relevé du soir (courses non traitées…)',
    ];

    /** Export Excel de la période (onglets avec montants : droit caisse) */
    public function export(Request $request, StatsExport $export): BinaryFileResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null || ! $user->can(Permission::OrdersView->value), 403);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'activity' => ['nullable', Rule::enum(Activity::class)],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 366, 422, 'Choisissez une période d\'un an au plus.');
        $path = $export->xlsx($user->company->fresh(), $from, $to, $user->can(Permission::FinanceView->value), $data['activity'] ?? null);

        return response()->download($path, "statistiques-{$data['from']}-au-{$data['to']}.xlsx")->deleteFileAfterSend();
    }

    /** Lien Google Sheets : adresses des jeux de données (null : pas de lien) */
    public function feedLink(Request $request): JsonResponse
    {
        $company = $this->manager($request);

        return response()->json(['data' => $this->links($company)]);
    }

    /** Nouveau lien (l'ancien ne marche plus) */
    public function regenerateFeed(Request $request): JsonResponse
    {
        $company = $this->manager($request);
        $company->forceFill(['stats_feed_token' => Str::random(48)])->save();

        return response()->json(['data' => $this->links($company)]);
    }

    public function disableFeed(Request $request): JsonResponse
    {
        $company = $this->manager($request);
        $company->forceFill(['stats_feed_token' => null])->save();

        return response()->json(['data' => $this->links($company)]);
    }

    /** Point par e-mail tout de suite (essai), aux adresses enregistrées */
    public function testEmail(Request $request, StatsMailer $mailer): JsonResponse
    {
        $company = $this->manager($request);
        $sent = $mailer->send($company, $request->input('frequency') === 'monthly' ? 'monthly' : 'weekly');
        abort_unless($sent, 422, 'Ajoutez au moins une adresse e-mail.');

        return response()->json(['data' => ['sent' => true]]);
    }

    /**
     * Données pour Google Sheets (=IMPORTDATA) : CSV sans connexion, protégé par la clé secrète du lien.
     * Période : ?jours=30 (1 à 366) pour les jeux sur une période.
     */
    public function feed(Request $request, string $token, string $dataset, Profitability $profitability, Performance $performance, ServiceQuality $quality): Response
    {
        $dataset = Str::before($dataset, '.csv');
        $company = strlen($token) >= 32 ? Company::query()->where('stats_feed_token', $token)->first() : null;
        abort_if($company === null || ! array_key_exists($dataset, self::FEEDS), 404);
        $days = max(1, min(366, (int) $request->query('jours', 30)));
        $tz = $company->timezone ?: config('app.timezone');
        $to = CarbonImmutable::now($tz)->startOfDay();
        $from = $to->subDays($days - 1);

        [$header, $rows] = match ($dataset) {
            'mois' => [['mois', 'creees', 'livrees', 'echecs', 'reussite_pct', 'chiffre_affaires', 'couts', 'marge'],
                array_map(fn ($m) => [$m['month'], $m['created'], $m['delivered'], $m['failed'], $m['success_rate'], $m['revenue'], $m['costs'], $m['margin']], $performance->build($company, true)['months'])],
            'clients' => [['client', 'livrees', 'revenus', 'couts_directs', 'frais_fixes', 'marge', 'taux_marge_pct', 'verdict'],
                array_map(fn ($c) => [$c['name'], $c['courses'], $c['revenue'], $c['direct_costs'], $c['fixed_share'], $c['margin'], $c['margin_rate'], $c['verdict']], $profitability->build($company, $from, $to)['clients'])],
            'zones' => [['zone', 'livrees', 'tarif_moyen', 'cout_par_livraison', 'revenus', 'marge', 'taux_marge_pct'],
                array_map(fn ($z) => [$z['name'], $z['delivered'], $z['average_fee'], $z['cost_per_delivery'], $z['revenue'], $z['margin'], $z['margin_rate']], $profitability->build($company, $from, $to)['zones'])],
            'livreurs' => [['livreur', 'missions', 'livraisons', 'jours', 'livraisons_par_jour', 'paie', 'vehicule', 'cout_par_mission', 'marge'],
                array_map(fn ($c) => [$c['name'], $c['missions'], $c['deliveries'], $c['days_worked'], $c['per_day'], $c['pay'], $c['vehicle'], $c['cost_per_mission'], $c['margin']], $profitability->build($company, $from, $to)['couriers'])],
            'delais' => [['client', 'livrees', 'a_l_heure', 'en_retard', 'taux_pct'],
                array_map(fn ($r) => [$r['label'], $r['total'], $r['on_time'], $r['late'], $r['rate']], $quality->build($company, $from, $to)['delays']['by_client'])],
            'echecs' => [['motif', 'nombre'], array_map(fn ($r) => [$r['label'], $r['count']], $quality->build($company, $from, $to)['failures']['reasons'])],
            'releves' => [['jour', 'non_traitees', 'delai_interne_depasse', 'sans_livreur', 'livrees', 'creees'],
                array_map(fn ($h) => [$h['date'], $h['untreated'] ?? null, $h['internal_late'] ?? null, $h['unassigned'] ?? null, $h['delivered'] ?? null, $h['created'] ?? null], $quality->build($company, $from, $to)['history'])],
        };

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function manager(Request $request): Company
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null || ! $user->can(Permission::SettingsManage->value), 403);

        return $user->company->fresh();
    }

    /** @return array{enabled: bool, feeds: list<array{key: string, label: string, url: ?string, formula: ?string}>} */
    private function links(Company $company): array
    {
        $token = $company->stats_feed_token;

        return [
            'enabled' => $token !== null,
            'feeds' => collect(self::FEEDS)->map(function (string $label, string $key) use ($token) {
                $url = $token ? url("/api/v1/stats-feed/{$token}/{$key}.csv") : null;

                return ['key' => $key, 'label' => $label, 'url' => $url, 'formula' => $url ? "=IMPORTDATA(\"{$url}\")" : null];
            })->values()->all(),
        ];
    }
}
