<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Reports\ClientInsights;
use App\Services\Reports\ClientReports;
use App\Services\Reports\DirectionActivities;
use App\Services\Reports\DirectionBoard;
use App\Services\Reports\DirectionForecast;
use App\Services\Reports\DirectionObjectives;
use App\Services\Reports\DirectionTeams;
use App\Services\Reports\Performance;
use App\Services\Reports\Pivot;
use App\Services\Reports\Profitability;
use App\Services\Reports\ServiceQuality;
use App\Services\Reports\Statistics;
use App\Services\Reports\StatsQuestions;
use App\Services\Reports\StatsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Statistiques de l'entreprise (back-office) : vue d'ensemble, livraisons, livreurs,
 * clients, zones, finances.
 * Les montants (chiffre d'affaires, encaissements, gains) sont réservés aux comptes
 * qui voient la caisse.
 */
class StatisticsController extends Controller
{
    public function __construct(private readonly Statistics $statistics) {}

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->overview($from, $to, $this->withAmounts($request))]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->deliveries($from, $to)]);
    }

    public function couriers(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::OrdersDispatch->value), 403);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->couriers($from, $to, $this->withAmounts($request))]);
    }

    public function merchants(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::MerchantsView->value), 403);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->merchants($from, $to, $this->withAmounts($request))]);
    }

    public function zones(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->zones($from, $to, $this->withAmounts($request))]);
    }

    public function finances(Request $request): JsonResponse
    {
        abort_unless($this->withAmounts($request), 403);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->stats($request)->finances($from, $to)]);
    }

    /** Rentabilité : clients, zones, livreurs, contrats (montants : droit caisse) */
    public function profitability(Request $request, Profitability $profitability): JsonResponse
    {
        abort_unless($this->withAmounts($request), 403);
        $this->stats($request);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $profitability->build($request->user()->company->fresh(), $from, $to, $request->input('activity'))]);
    }

    /** Délais, échecs, distribution, historique des courses non traitées */
    public function quality(Request $request, ServiceQuality $quality): JsonResponse
    {
        $this->stats($request);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $quality->build($request->user()->company->fresh(), $from, $to, $request->input('activity'))]);
    }

    /** Clients : fidélité par mois d'arrivée, clients en baisse, concentration 80/20, délais de paiement */
    public function clients(Request $request, ClientInsights $insights): JsonResponse
    {
        abort_unless($request->user()->can(Permission::MerchantsView->value), 403);
        $this->stats($request);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $insights->build($request->user()->company->fresh(), $from, $to, $this->withAmounts($request), $request->input('activity'))]);
    }

    /** Analyse croisée : deux axes et un indicateur (chiffre d'affaires : droit caisse) */
    public function pivot(Request $request, Pivot $pivot): JsonResponse
    {
        $this->stats($request);
        $data = $request->validate([
            'rows' => ['required', Rule::in(array_keys(Pivot::DIMENSIONS))],
            'cols' => ['nullable', 'different:rows', Rule::in(array_keys(Pivot::DIMENSIONS))],
            'metric' => ['required', Rule::in(array_keys(Pivot::METRICS))],
        ]);
        abort_if($data['metric'] === 'revenue' && ! $this->withAmounts($request), 403);
        abort_if(in_array('courier', [$data['rows'], $data['cols'] ?? null], true) && ! $request->user()->can(Permission::OrdersDispatch->value), 403);
        abort_if(in_array('client', [$data['rows'], $data['cols'] ?? null], true) && ! $request->user()->can(Permission::MerchantsView->value), 403);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => [
            ...$pivot->build($request->user()->company->fresh(), $from, $to, $data['rows'], $data['cols'] ?? null, $data['metric'], $request->input('activity')),
            'dimensions' => Pivot::DIMENSIONS,
            'metrics' => Pivot::METRICS,
        ]]);
    }

    /** Objectifs du mois et tendances sur 13 mois (montants pour qui voit la caisse) */
    public function performance(Request $request, Performance $performance): JsonResponse
    {
        $this->stats($request);

        return response()->json(['data' => $performance->build($request->user()->company->fresh(), $this->withAmounts($request), $request->input('activity'))]);
    }

    /**
     * Application du client : ses propres courses (vue d'ensemble, qualité des
     * livraisons, communes de livraison). Montants pour les comptes qui voient ses paiements.
     */
    public function merchant(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_if($merchant === null, 403);
        [$from, $to] = $this->period($request);
        $request->validate(['activity' => ['nullable', Rule::enum(Activity::class)]]);
        $activity = $request->input('activity');

        // Une de ses activités, ou toutes
        $stats = $this->statistics->forMerchant($merchant)->forActivity($activity);
        $withAmounts = $this->withAmounts($request);
        $overview = $stats->overview($from, $to, $withAmounts);
        $deliveries = $stats->deliveries($from, $to);

        return response()->json(['data' => [
            'period' => $overview['period'],
            'previous_period' => $overview['previous_period'],
            'kpis' => $overview['kpis'],
            'previous_kpis' => $overview['previous_kpis'],
            'series' => $overview['series'],
            'first_attempt_rate' => $deliveries['first_attempt_rate'],
            'same_day_rate' => $deliveries['same_day_rate'],
            'delays' => $deliveries['delays'],
            'failure_reasons' => $deliveries['failure_reasons'],
            'outcomes' => $deliveries['outcomes'],
            // Distribution, MAD : envois par type, destination et délai
            'shipments' => $activity !== Activity::Ecommerce->value && ($merchant->hasActivity(Activity::Distribution) || $merchant->hasActivity(Activity::Mad))
                ? $stats->shipments($from, $to) : null,
            // Livrées dans le délai et courses par département
            'punctuality' => app(ClientReports::class)->forClientSpace($merchant, $from, $to, $activity),
            'zones' => collect($stats->zones($from, $to, false)['zones'])->take(8)
                ->map(fn (array $z) => collect($z)->only(['id', 'name', 'delivered', 'failed', 'success_rate'])->all())->values(),
        ]]);
    }

    private function stats(Request $request): Statistics
    {
        // Personnel de l'entreprise seulement (pas les clients)
        abort_if($request->user()->merchant_id !== null || $request->user()->company === null, 403);

        // Une activité choisie (dans le périmètre de l'utilisateur, filtré par ailleurs)
        $request->validate(['activity' => ['nullable', Rule::enum(Activity::class)]]);

        return $this->statistics->for($request->user()->company)->forActivity($request->input('activity'));
    }

    /**
     * Statistiques de direction › Vue d'ensemble : les activités côte à côte et les points à décider.
     * Réservé à qui a l'autorisation « Voir les statistiques de direction », dans son périmètre d'activités.
     */
    public function direction(Request $request, DirectionBoard $board): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        [$from, $to] = $this->period($request);
        $activities = array_values(array_filter(Activity::cases(), fn (Activity $a) => $user->seesActivity($a)));

        return response()->json(['data' => $board->overview($user->company->fresh(), $from, $to, $activities)]);
    }

    /**
     * Statistiques de direction › une activité (e-commerce, MAD, distribution) : ses indicateurs propres.
     * Refusé hors du périmètre d'activités de la personne.
     */
    public function directionActivity(Request $request, string $activity, DirectionActivities $screens): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        abort_unless(Activity::tryFrom($activity) !== null && $user->seesActivity($activity), 403);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $screens->{$activity}($user->company->fresh(), $from, $to)]);
    }

    /**
     * Statistiques de direction › Équipes : livreurs, charge par zone, paie, véhicules, agents du bureau.
     */
    public function directionTeams(Request $request, DirectionTeams $teams): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $teams->build($user->company->fresh(), $from, $to)]);
    }

    /**
     * Statistiques de direction › Aide à la décision : prévision du chiffre d'affaires sur trois mois, par activité.
     */
    public function directionForecast(Request $request, DirectionForecast $forecast): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);

        return response()->json(['data' => $forecast->forecast($user->company->fresh(), $this->visibleActivities($request))]);
    }

    /**
     * Statistiques de direction › Aide à la décision : chiffres du dernier mois terminé, point de départ du simulateur.
     */
    public function directionSimulator(Request $request, DirectionForecast $forecast): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);

        return response()->json(['data' => $forecast->baseline($user->company->fresh(), $this->visibleActivities($request))]);
    }

    /**
     * Statistiques de direction › Questions (IA) : la fonction est-elle active (clé Anthropic réglée) ?
     */
    public function directionQuestions(Request $request, StatsQuestions $questions): JsonResponse
    {
        abort_if($request->user()->merchant_id !== null || $request->user()->company === null, 403);

        return response()->json(['data' => ['enabled' => $questions->enabled()]]);
    }

    /**
     * Pose une question : Claude la traduit en demande (il ne reçoit que la question), l'application calcule.
     */
    public function askDirectionQuestion(Request $request, StatsQuestions $questions): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        abort_unless($questions->enabled(), 409, 'Réglez la clé Anthropic pour poser des questions.');
        $data = $request->validate(['question' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => ['question' => $data['question'], ...$questions->ask($user->company->fresh(), $user, $data['question'])]]);
    }

    /** @return list<Activity> activités du périmètre de la personne */
    private function visibleActivities(Request $request): array
    {
        return array_values(array_filter(Activity::cases(), fn (Activity $a) => $request->user()->seesActivity($a)));
    }

    /**
     * Statistiques de direction › Objectifs : les objectifs du mois, par activité et pour les équipes, face au réalisé.
     */
    public function directionObjectives(Request $request, DirectionObjectives $objectives): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']], [], ['month' => 'mois']);
        $now = CarbonImmutable::now($user->company->timezone ?: config('app.timezone'));
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay() : $now->startOfMonth();
        if ($month->greaterThan($now)) {
            throw ValidationException::withMessages(['month' => 'Choisissez le mois en cours ou un mois passé.']);
        }
        $activities = array_values(array_filter(Activity::cases(), fn (Activity $a) => $user->seesActivity($a)));

        return response()->json(['data' => [
            ...$objectives->build($user->company->fresh(), $month, $activities),
            'can_edit' => $user->can(Permission::SettingsManage->value),
        ]]);
    }

    /**
     * Fixe les objectifs (réglage de l'entreprise) : seuls ceux du périmètre d'activités de la personne changent.
     */
    public function saveDirectionObjectives(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        abort_unless($user->can(Permission::SettingsManage->value), 403);
        $rules = ['objectives' => ['required', 'array:'.implode(',', StatsSettings::OBJECTIVE_SCOPES)]];
        foreach (StatsSettings::OBJECTIVE_SCOPES as $scope) {
            $metrics = StatsSettings::OBJECTIVES[$scope === 'teams' ? 'teams' : 'activity'];
            $rules["objectives.{$scope}"] = ['nullable', 'array:'.implode(',', array_keys($metrics))];
            foreach ($metrics as $key => $metric) {
                $rules["objectives.{$scope}.{$key}"] = ['nullable', 'numeric', $key === 'margin' ? 'min:-1000000000' : 'min:0', $metric['kind'] === 'percent' ? 'max:100' : 'max:100000000000'];
            }
        }
        $input = $request->validate($rules)['objectives'];

        $company = $user->company->fresh();
        $settings = (array) ($company->stats_settings ?? []);
        $objectives = StatsSettings::cleanObjectives((array) ($settings['objectives'] ?? []));
        foreach ($input as $scope => $values) {
            if ($scope === 'teams' || $user->seesActivity($scope)) {
                $objectives[$scope] = [...$objectives[$scope], ...(array) $values];
            }
        }
        $company->update(['stats_settings' => [...$settings, 'objectives' => StatsSettings::cleanObjectives($objectives)]]);

        return response()->json(['data' => ['objectives' => $company->fresh()->stats_settings['objectives']]]);
    }

    private function withAmounts(Request $request): bool
    {
        return $request->user()->can(Permission::FinanceView->value);
    }

    /**
     * Période demandée (dates du fuseau de l'entreprise) ; par défaut le mois en cours.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [], ['from' => 'début', 'to' => 'fin']);

        $today = CarbonImmutable::now($request->user()->company?->timezone ?: config('app.timezone'))->startOfDay();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')) : $today->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')) : $today;

        if ($from->diffInDays($to) + 1 > Statistics::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choisissez une période d\'un an au plus.']);
        }

        return [$from, $to];
    }
}
