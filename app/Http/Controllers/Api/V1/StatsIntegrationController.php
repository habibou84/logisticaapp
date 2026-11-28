<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Mail\StatsReportMail;
use App\Models\Company;
use App\Notifications\StatsAlertRaised;
use App\Services\Reports\Performance;
use App\Services\Reports\Profitability;
use App\Services\Reports\ServiceQuality;
use App\Services\Reports\StatsExport;
use App\Services\Reports\StatsMailer;
use App\Services\Reports\StatsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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

    /**
     * Export de la période : Excel complet par défaut ; avec des options, les rubriques, le format (Excel, CSV, PDF),
     * les montants masqués et les livreurs anonymisés au choix. Montants : droit caisse.
     */
    public function export(Request $request, StatsExport $export): BinaryFileResponse
    {
        [$company, $from, $to, $options] = $this->exportRequest($request);
        $path = $export->file($company, $from, $to, $options);

        return response()->download($path, "statistiques-{$from->toDateString()}-au-{$to->toDateString()}.{$options['format']}")->deleteFileAfterSend();
    }

    /** Ce qu'on peut exporter, et les modèles enregistrés */
    public function exportOptions(Request $request, StatsSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null || ! $user->can(Permission::OrdersView->value), 403);
        $amounts = $user->can(Permission::FinanceView->value);
        $saved = $settings->for($user->company->fresh());

        return response()->json(['data' => [
            'sections' => collect(StatsExport::SECTIONS)->filter(fn (array $s) => $amounts || ! $s[1])
                ->map(fn (array $s, string $key) => ['key' => $key, 'label' => $s[0], 'amounts' => $s[1], 'default' => in_array($key, StatsExport::DEFAULT_SECTIONS, true)])->values(),
            'formats' => StatsExport::FORMATS,
            'can_amounts' => $amounts,
            'can_save' => $user->can(Permission::DirectionView->value),
            'can_send' => $user->can(Permission::SettingsManage->value),
            'templates' => $saved['exports'],
            'recipients' => $saved['email']['recipients'],
        ]]);
    }

    /** Enregistre l'export comme modèle (même nom : remplacé) */
    public function saveExportTemplate(Request $request, StatsSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null || ! $user->can(Permission::DirectionView->value), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'options' => ['required', 'array']]);
        $company = $user->company->fresh();
        $templates = collect($settings->for($company)['exports'])->reject(fn ($t) => mb_strtolower($t['name']) === mb_strtolower(trim($data['name'])));
        abort_if($templates->count() >= StatsSettings::MAX_EXPORTS, 422, 'Vous avez déjà '.StatsSettings::MAX_EXPORTS.' modèles : supprimez-en un d\'abord.');
        $templates->push(['id' => (string) Str::uuid(), 'name' => $data['name'], ...StatsSettings::cleanExportOptions($data['options'])]);

        return response()->json(['data' => ['templates' => $this->storeTemplates($company, $templates->values()->all())]]);
    }

    public function deleteExportTemplate(Request $request, string $template, StatsSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null || ! $user->can(Permission::DirectionView->value), 403);
        $company = $user->company->fresh();

        return response()->json(['data' => ['templates' => $this->storeTemplates($company, collect($settings->for($company)['exports'])->where('id', '!=', $template)->values()->all())]]);
    }

    /** Envoi régulier d'un modèle : chaque lundi, le 1er du mois, ou jamais (aux adresses du point par e-mail) */
    public function scheduleExportTemplate(Request $request, string $template, StatsSettings $settings): JsonResponse
    {
        $company = $this->manager($request);
        $data = $request->validate(['schedule' => ['nullable', Rule::in(['weekly', 'monthly'])]]);
        $templates = collect($settings->for($company)['exports'])->map(fn (array $t) => $t['id'] === $template ? [...$t, 'schedule' => $data['schedule'] ?? null] : $t);
        abort_unless($templates->contains('id', $template), 404);

        return response()->json(['data' => ['templates' => $this->storeTemplates($company, $templates->all())]]);
    }

    /** Alertes par événement : réglage de chacune, destinataires, dernières alertes reçues */
    public function alerts(Request $request, StatsSettings $settings): JsonResponse
    {
        $company = $this->manager($request);
        $events = $settings->alertEvents($company);

        return response()->json(['data' => [
            'events' => collect(StatsSettings::ALERT_EVENTS)->map(fn (array $e, string $key) => ['key' => $key, 'label' => $e['label'], 'unit' => $e['unit'], ...$events[$key]])->values(),
            'recipients' => $settings->alertRecipients($company),
            'min_volume' => $settings->for($company)['alerts']['min_volume'],
            'recent' => $request->user()->notifications()->where('type', StatsAlertRaised::class)->latest()->limit(8)->get()
                ->map(fn ($n) => ['title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '', 'href' => $n->data['href'] ?? null, 'at' => $n->created_at?->toIso8601String()])->values(),
        ]]);
    }

    public function saveAlerts(Request $request, StatsSettings $settings): JsonResponse
    {
        $company = $this->manager($request);
        $rules = [
            'events' => ['required', 'array:'.implode(',', array_keys(StatsSettings::ALERT_EVENTS))],
            'recipients' => ['present', 'array', 'max:10'],
            'recipients.*' => ['email:rfc', 'max:150'],
            'min_volume' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
        foreach (array_keys(StatsSettings::ALERT_EVENTS) as $key) {
            $rules["events.{$key}"] = ['array:active,threshold,bell,whatsapp,email'];
            $rules["events.{$key}.threshold"] = ['nullable', 'integer', 'min:0', 'max:100000000'];
            foreach (['active', ...StatsSettings::ALERT_CHANNELS] as $flag) {
                $rules["events.{$key}.{$flag}"] = ['nullable', 'boolean'];
            }
        }
        $data = $request->validate($rules, [], ['recipients' => 'destinataires']);
        $settings->storeAlerts($company, $data['events'], $data['recipients'], $data['min_volume'] ?? null);

        return $this->alerts($request, $settings);
    }

    /** Envoie l'export par e-mail, en pièce jointe, aux adresses données */
    public function sendExport(Request $request, StatsExport $export): JsonResponse
    {
        abort_unless($request->user()->can(Permission::SettingsManage->value), 403);
        $mail = $request->validate(['recipients' => ['required', 'array', 'min:1', 'max:10'], 'recipients.*' => ['email:rfc', 'max:150']], [], ['recipients' => 'destinataires']);
        [$company, $from, $to, $options] = $this->exportRequest($request);
        $path = $export->file($company, $from, $to, $options);
        $period = $from->format('d/m/Y').' au '.$to->format('d/m/Y');
        try {
            Mail::to($mail['recipients'])->send(new StatsReportMail($company, "Statistiques du {$period}",
                $export->summary($company, $from, $to, $options['amounts']), $path, "statistiques-{$from->toDateString()}-au-{$to->toDateString()}.{$options['format']}"));
        } finally {
            @unlink($path);
        }

        return response()->json(['data' => ['sent' => count($mail['recipients'])]]);
    }

    /**
     * Demande d'export vérifiée : entreprise, période (un an au plus) et options ; montants seulement avec le droit
     * caisse et s'ils ne sont pas masqués.
     *
     * @return array{0: Company, 1: CarbonImmutable, 2: CarbonImmutable, 3: array<string, mixed>}
     */
    private function exportRequest(Request $request): array
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null || ! $user->can(Permission::OrdersView->value), 403);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'activity' => ['nullable', Rule::enum(Activity::class)],
            'sections' => ['nullable', 'array'],
            'sections.*' => [Rule::in(array_keys(StatsExport::SECTIONS))],
            'format' => ['nullable', Rule::in(array_keys(StatsExport::FORMATS))],
            'hide_amounts' => ['nullable', 'boolean'],
            'anonymize' => ['nullable', 'boolean'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 366, 422, 'Choisissez une période d\'un an au plus.');
        abort_if(($data['activity'] ?? null) !== null && ! $user->seesActivity($data['activity']), 403);

        return [$user->company->fresh(), $from, $to, [
            'sections' => $data['sections'] ?? StatsExport::DEFAULT_SECTIONS,
            'format' => $data['format'] ?? 'xlsx',
            'activity' => $data['activity'] ?? null,
            'amounts' => $user->can(Permission::FinanceView->value) && ! $request->boolean('hide_amounts'),
            'anonymize' => $request->boolean('anonymize'),
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $templates
     * @return list<array<string, mixed>>
     */
    private function storeTemplates(Company $company, array $templates): array
    {
        $clean = StatsSettings::cleanExports($templates);
        $company->update(['stats_settings' => [...(array) ($company->stats_settings ?? []), 'exports' => $clean]]);

        return $clean;
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
