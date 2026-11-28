<?php

namespace App\Services\Reports;

use App\Enums\AssignmentStatus;
use App\Enums\EarningType;
use App\Enums\OrderEventType;
use App\Models\CashClosing;
use App\Models\Company;
use App\Models\CourierAbsence;
use App\Models\CourierEarning;
use App\Models\CourierRating;
use App\Models\CourierRemittance;
use App\Models\CourierShift;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderEvent;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleLog;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statistiques de direction › Équipes : les livreurs (productivité, temps par course, réussite, note, retards, écarts de
 * caisse, paie), la charge par zone, la paie par mois, les véhicules et les agents du bureau (répartition, caisse,
 * dépôt).
 *
 * Temps d'une course : de l'affectation à la fin de la mission, en trois étapes (acceptation, attente avant le départ,
 * trajet et remise). Les durées aberrantes (oubli d'appuyer, validation en lot) sont écartées : voir les bornes.
 */
class DirectionTeams
{
    /** Bornes d'une étape, en minutes : au-delà, la mesure est écartée */
    private const MAX_ACCEPT = 120;

    private const MAX_WAIT = 240;

    private const MAX_RUN = 240;

    /** Une zone est en surcharge au-delà de cette part de la charge moyenne (en %) ; en sous-charge sous l'autre */
    public const LOAD_HIGH = 125;

    public const LOAD_LOW = 60;

    /** Livreur « à féliciter » à partir de ce taux de réussite ; « à accompagner » sous l'autre */
    public const SUCCESS_GOOD = 90;

    public const SUCCESS_LOW = 75;

    private const PAY_GROUPS = [
        'course' => ['pickup', 'delivery', 'return', 'failed_attempt'],
        'fixed' => ['salary', 'mad_days'],
    ];

    public function __construct(private readonly Statistics $statistics, private readonly StatsSettings $settings) {}

    /** @return array<string, mixed> */
    public function build(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        [$prevFrom, $prevTo] = Statistics::previous($from, $to);
        $range = $this->range($tz, $from, $to);
        $min = (int) $this->settings->for($company)['alerts']['min_volume'];
        $stats = $this->statistics->for($company);

        $missions = $this->missions($company, $range);
        $times = $this->times($missions);
        $before = $this->times($this->missions($company, $this->range($tz, $prevFrom, $prevTo)));
        $rows = collect($stats->couriers($from, $to, true)['couriers']);
        $earlier = collect($stats->couriers($prevFrom, $prevTo, true)['couriers']);

        $ratings = CourierRating::forCompany($company->id)->whereNull('neutralized_at')->whereBetween('created_at', $range)
            ->selectRaw('courier_id, AVG(stars) AS stars, COUNT(*) AS n')->groupBy('courier_id')->get()->keyBy('courier_id');
        $shifts = CourierShift::forCompany($company->id)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('courier_id, AVG(late_minutes) AS late, COUNT(*) AS n')->groupBy('courier_id')->get()->keyBy('courier_id');
        $absences = CourierAbsence::forCompany($company->id)->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())
            ->get(['courier_id', 'starts_on', 'ends_on'])->groupBy('courier_id')
            ->map(fn (Collection $list) => (int) $list->sum(fn ($a) => CarbonImmutable::parse($a->starts_on)->max($from)->diffInDays(CarbonImmutable::parse($a->ends_on)->min($to)) + 1));
        $byCourier = $missions->groupBy('courier_id');

        $perDay = fn (Collection $list) => $list->sum('days_worked') > 0 ? round($list->sum('delivered') / $list->sum('days_worked'), 1) : null;
        $average = $perDay($rows);
        $couriers = $rows->map(function (array $c) use ($ratings, $shifts, $absences, $byCourier, $average, $min) {
            $minutes = $this->average($byCourier->get($c['id'], collect())->pluck('run'));
            $volume = $c['delivered'] + $c['failed'];
            $verdict = null;
            if ($volume >= $min && $c['success_rate'] !== null) {
                $verdict = match (true) {
                    $c['success_rate'] < self::SUCCESS_LOW, $c['cash_gap'] < 0, $average && $c['per_day'] !== null && $c['per_day'] < $average / 2 => 'support',
                    $c['success_rate'] >= self::SUCCESS_GOOD && $average && $c['per_day'] >= $average => 'praise',
                    default => 'average',
                };
            }

            return [
                ...collect($c)->only(['id', 'name', 'delivered', 'pickups', 'failed', 'success_rate', 'days_worked', 'per_day', 'cash_gap', 'earnings'])->all(),
                'minutes' => $minutes,
                'rating' => isset($ratings[$c['id']]) ? round((float) $ratings[$c['id']]->stars, 1) : null,
                'late_minutes' => isset($shifts[$c['id']]) ? (int) round((float) $shifts[$c['id']]->late) : null,
                'absence_days' => (int) ($absences[$c['id']] ?? 0),
                'pay_per_delivery' => $c['delivered'] > 0 ? (int) round($c['earnings'] / $c['delivered']) : null,
                'verdict' => $verdict,
            ];
        })->values();

        $working = $couriers->where('days_worked', '>', 0);
        $delivered = (int) $couriers->sum('delivered');
        $pay = (int) $couriers->sum('earnings');
        $payBefore = (int) $earlier->sum('earnings');
        $stars = $ratings->sum('n') > 0 ? round($ratings->sum(fn ($r) => (float) $r->stars * $r->n) / $ratings->sum('n'), 1) : null;
        $zones = $this->zones($missions, $tz);
        $staff = $this->staff($company, $range, $from, $to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'kpis' => [
                'couriers' => $working->count(),
                'previous_couriers' => $earlier->where('days_worked', '>', 0)->count(),
                'per_day' => $average, 'previous_per_day' => $perDay($earlier),
                'minutes' => $times['total'], 'previous_minutes' => $before['total'],
                'success_rate' => $delivered + $couriers->sum('failed') > 0 ? round($delivered / ($delivered + $couriers->sum('failed')) * 100, 1) : null,
                'rating' => $stars,
                'late_minutes' => $shifts->sum('n') > 0 ? (int) round($shifts->sum(fn ($s) => (float) $s->late * $s->n) / $shifts->sum('n')) : null,
                'pay' => $pay, 'previous_pay' => $payBefore,
                'pay_per_delivery' => $delivered > 0 ? (int) round($pay / $delivered) : null,
                'previous_pay_per_delivery' => $earlier->sum('delivered') > 0 ? (int) round($payBefore / $earlier->sum('delivered')) : null,
                'cash_gap' => (int) $couriers->sum('cash_gap'),
                'staff' => User::query()->where('company_id', $company->id)->whereNull('merchant_id')->where('status', 'active')->doesntHave('courier')->count(),
            ],
            'times' => $times,
            'couriers' => $couriers->all(),
            'zones' => $zones,
            'pay_months' => $this->payMonths($company, $tz),
            'vehicles' => $this->vehicles($company, $from, $to, $delivered),
            'staff' => $staff,
            'thresholds' => ['load_high' => self::LOAD_HIGH, 'load_low' => self::LOAD_LOW, 'success_good' => self::SUCCESS_GOOD, 'success_low' => self::SUCCESS_LOW, 'min_volume' => $min],
            'decisions' => $this->decisions($couriers, $zones, $staff),
        ];
    }

    /**
     * Missions terminées sur la période, avec leurs trois durées (minutes ; null : écartée).
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return Collection<int, array<string, mixed>>
     */
    private function missions(Company $company, array $range): Collection
    {
        $orders = Order::forCompany($company->id)->select('id');
        $minutes = fn ($a, $b, int $max, int $floor = 0) => $a && $b && ($m = (int) round($a->diffInSeconds($b, false) / 60)) >= $floor && $m <= $max ? $m : null;

        return OrderAssignment::query()->whereIn('order_id', $orders)->where('status', AssignmentStatus::Completed->value)
            ->whereBetween('completed_at', $range)->with('order:id,activity,delivery_zone_id,created_at')
            ->get(['id', 'order_id', 'courier_id', 'type', 'assigned_by', 'assigned_at', 'accepted_at', 'started_at', 'completed_at'])
            ->filter(fn (OrderAssignment $m) => $m->order !== null)
            ->map(fn (OrderAssignment $m) => [
                'courier_id' => $m->courier_id,
                'type' => $m->type->value,
                'activity' => $m->order->activity instanceof \BackedEnum ? $m->order->activity->value : (string) $m->order->activity,
                'zone_id' => $m->order->delivery_zone_id,
                'completed_at' => $m->completed_at,
                'accept' => $minutes($m->assigned_at, $m->accepted_at, self::MAX_ACCEPT),
                'wait' => $minutes($m->accepted_at ?? $m->assigned_at, $m->started_at, self::MAX_WAIT),
                // Une minute au moins : en dessous, la mission a été validée sans être chronométrée
                'run' => $minutes($m->started_at, $m->completed_at, self::MAX_RUN, 1),
            ])->values();
    }

    /**
     * Temps moyen d'une mission, étape par étape : toutes activités, puis par activité.
     *
     * @param  Collection<int, array<string, mixed>>  $missions
     * @return array<string, mixed>
     */
    private function times(Collection $missions): array
    {
        $steps = function (Collection $list) {
            $parts = ['accept' => $this->average($list->pluck('accept')), 'wait' => $this->average($list->pluck('wait')), 'run' => $this->average($list->pluck('run'))];

            return [...$parts, 'total' => $parts['run'] === null ? null : array_sum($parts), 'median' => $this->median($list->pluck('run')), 'measured' => $list->whereNotNull('run')->count(), 'missions' => $list->count()];
        };
        $labels = ['ecommerce' => 'E-commerce', 'distribution' => 'Distribution', 'mad' => 'MAD'];

        return [
            ...$steps($missions),
            'by_activity' => collect($labels)->map(fn (string $label, string $a) => ['activity' => $a, 'label' => $label, ...$steps($missions->where('activity', $a))])
                ->filter(fn ($r) => $r['missions'] > 0)->values()->all(),
        ];
    }

    /**
     * Charge par zone de livraison : livraisons par livreur et par jour, et temps moyen du trajet.
     *
     * @param  Collection<int, array<string, mixed>>  $missions
     * @return array<string, mixed>
     */
    private function zones(Collection $missions, string $tz): array
    {
        $deliveries = $missions->where('type', 'delivery');
        $days = fn (Collection $list) => $list->map(fn ($m) => $m['courier_id'].'|'.CarbonImmutable::parse($m['completed_at'])->setTimezone($tz)->toDateString())->unique()->count();
        $load = fn (Collection $list) => ($d = $days($list)) > 0 ? round($list->count() / $d, 1) : null;
        $names = Zone::withoutGlobalScopes()->whereIn('id', $deliveries->pluck('zone_id')->filter()->unique())->pluck('name', 'id');

        // Référence : la charge moyenne des zones, pondérée par leurs livraisons (un livreur sert plusieurs zones par jour)
        $groups = $deliveries->groupBy('zone_id');
        $average = $deliveries->count() > 0 ? round($groups->sum(fn (Collection $list) => $load($list) * $list->count()) / $deliveries->count(), 1) : null;

        $rows = $groups->map(function (Collection $list, $zoneId) use ($names, $load, $average) {
            $value = $load($list);
            $share = $average ? (int) round($value / $average * 100) : null;

            return [
                'id' => $zoneId ? (int) $zoneId : null,
                'name' => $names[$zoneId] ?? 'Sans zone',
                'deliveries' => $list->count(),
                'couriers' => $list->pluck('courier_id')->unique()->count(),
                'per_courier_day' => $value,
                'minutes' => $this->average($list->pluck('run')),
                'level' => $share === null ? null : ($share >= self::LOAD_HIGH ? 'high' : ($share <= self::LOAD_LOW ? 'low' : 'normal')),
            ];
        })->sortByDesc('per_courier_day')->values()->all();

        return ['average' => $average, 'rows' => $rows];
    }

    /**
     * Paie des livreurs des six derniers mois : à la course, fixe et journées MAD, primes et indemnités.
     *
     * @return list<array<string, mixed>>
     */
    private function payMonths(Company $company, string $tz): array
    {
        $now = CarbonImmutable::now($tz);
        $first = $now->startOfMonth()->subMonths(5);
        $months = [];
        for ($m = $first; $m->lessThanOrEqualTo($now); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => ucfirst($m->locale('fr')->isoFormat('MMM YYYY')), 'course' => 0, 'fixed' => 0, 'other' => 0];
        }
        CourierEarning::forCompany($company->id)->whereBetween('created_at', [$first->utc(), $now->endOfDay()->utc()])->get(['type', 'amount', 'created_at'])
            ->each(function (CourierEarning $e) use (&$months, $tz) {
                $key = CarbonImmutable::parse($e->created_at)->setTimezone($tz)->format('Y-m');
                $type = $e->type instanceof EarningType ? $e->type->value : (string) $e->type;
                $group = in_array($type, self::PAY_GROUPS['course'], true) ? 'course' : (in_array($type, self::PAY_GROUPS['fixed'], true) ? 'fixed' : 'other');
                if (isset($months[$key])) {
                    $months[$key][$group] += (int) $e->amount;
                }
            });

        return array_values($months);
    }

    /**
     * Véhicules : parc, coûts payés par l'entreprise sur la période, jours d'immobilisation, papiers à renouveler.
     *
     * @return array<string, mixed>
     */
    private function vehicles(Company $company, CarbonImmutable $from, CarbonImmutable $to, int $delivered): array
    {
        $ids = Vehicle::forCompany($company->id)->where('status', '!=', 'sold')->pluck('id');
        $logs = VehicleLog::forCompany($company->id)->where('paid_by', 'company')->whereBetween('done_on', [$from->toDateString(), $to->toDateString()])->get(['amount', 'courier_share', 'immobilized_days']);
        $cost = (int) $logs->sum(fn ($l) => max(0, (int) $l->amount - (int) $l->courier_share));
        $today = CarbonImmutable::now($company->timezone ?: (string) config('app.timezone', 'UTC'))->toDateString();

        return [
            'count' => $ids->count(),
            'cost' => $cost,
            'cost_per_delivery' => $delivered > 0 ? (int) round($cost / $delivered) : null,
            'immobilized_days' => (int) $logs->sum('immobilized_days'),
            'documents_expiring' => VehicleDocument::withoutGlobalScopes()->whereIn('vehicle_id', $ids)
                ->whereDate('ends_on', '>=', $today)->whereDate('ends_on', '<=', CarbonImmutable::parse($today)->addDays(30)->toDateString())->count(),
            'documents_expired' => VehicleDocument::withoutGlobalScopes()->whereIn('vehicle_id', $ids)->whereDate('ends_on', '<', $today)->count(),
        ];
    }

    /**
     * Agents du bureau : ce que chacun a traité sur la période, son délai habituel et ses écarts.
     * - répartition : missions affectées, délai entre la création de la course et sa première affectation ;
     * - caisse : versements de livreurs reçus et écarts, clôtures de caisse ;
     * - dépôt : colis réceptionnés.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return list<array<string, mixed>>
     */
    private function staff(Company $company, array $range, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = Order::forCompany($company->id)->select('id');
        $rows = [];
        $seen = [];
        $bump = function (int $userId, string $field, int|float $value) use (&$rows) {
            $rows[$userId] ??= ['assigned' => 0, 'delays' => [], 'remittances' => 0, 'gap' => 0, 'closings' => 0, 'closing_gap' => 0, 'parcels' => 0];
            $field === 'delays' ? $rows[$userId]['delays'][] = $value : $rows[$userId][$field] += $value;
        };

        OrderAssignment::query()->whereIn('order_id', $orders)->whereNotNull('assigned_by')->whereBetween('assigned_at', $range)
            ->with('order:id,created_at')->orderBy('assigned_at')->get(['id', 'order_id', 'assigned_by', 'assigned_at'])
            ->each(function (OrderAssignment $m) use ($bump, &$seen) {
                $bump($m->assigned_by, 'assigned', 1);
                // Première affectation de la course : délai depuis sa création (au plus une journée)
                if (! isset($seen[$m->order_id]) && $m->order) {
                    $seen[$m->order_id] = true;
                    $minutes = (int) round($m->order->created_at->diffInSeconds($m->assigned_at, false) / 60);
                    if ($minutes >= 0 && $minutes <= 1440) {
                        $bump($m->assigned_by, 'delays', $minutes);
                    }
                }
            });
        CourierRemittance::forCompany($company->id)->whereNull('cancelled_at')->whereNotNull('received_by')->whereBetween('received_at', $range)
            ->get(['received_by', 'difference'])->each(function ($r) use ($bump) {
                $bump($r->received_by, 'remittances', 1);
                $bump($r->received_by, 'gap', min(0, (int) $r->difference));
            });
        CashClosing::forCompany($company->id)->whereNotNull('closed_by')->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->get(['closed_by', 'difference'])->each(function ($c) use ($bump) {
                $bump($c->closed_by, 'closings', 1);
                $bump($c->closed_by, 'closing_gap', (int) $c->difference);
            });
        OrderEvent::query()->whereIn('order_id', $orders)->where('type', OrderEventType::ParcelReceived->value)->where('actor_type', 'user')
            ->whereNotNull('actor_id')->whereBetween('created_at', $range)->selectRaw('actor_id, COUNT(*) AS n')->groupBy('actor_id')->pluck('n', 'actor_id')
            ->each(fn ($n, $userId) => $bump((int) $userId, 'parcels', (int) $n));

        // Les livreurs réceptionnent aussi des colis : seuls les comptes du bureau sont listés
        $users = User::query()->where('company_id', $company->id)->whereNull('merchant_id')->whereIn('id', array_keys($rows))->doesntHave('courier')->with('roles')->get()->keyBy('id');

        return collect($rows)->filter(fn ($r, $id) => isset($users[$id]))->map(fn (array $r, $id) => [
            'id' => (int) $id,
            'name' => $users[$id]->name,
            'role' => $users[$id]->roleLabel(),
            'assigned' => $r['assigned'],
            'assign_minutes' => $this->average(collect($r['delays'])),
            'remittances' => $r['remittances'],
            'gap' => $r['gap'],
            'closings' => $r['closings'],
            'closing_gap' => $r['closing_gap'],
            'parcels' => $r['parcels'],
        ])->sortByDesc(fn ($r) => $r['assigned'] + $r['remittances'] + $r['closings'] + $r['parcels'])->values()->all();
    }

    /**
     * Points « À décider » des équipes.
     *
     * @param  Collection<int, array<string, mixed>>  $couriers
     * @param  array<string, mixed>  $zones
     * @param  list<array<string, mixed>>  $staff
     * @return list<array<string, mixed>>
     */
    private function decisions(Collection $couriers, array $zones, array $staff): array
    {
        $out = [];
        $money = fn (int|float $v) => number_format(abs((int) round($v)), 0, ',', ' ').' F';
        $names = fn (Collection $list, callable $text) => $list->take(3)->map($text)->implode(', ').($list->count() > 3 ? '…' : '');
        $add = function (string $level, string $topic, string $title, string $body, string $href, string $action) use (&$out) {
            $out[] = ['level' => $level, 'activity' => null, 'activity_label' => 'Équipes', 'topic' => $topic, 'title' => $title, 'body' => str_replace('….', '…', $body), 'href' => $href, 'action' => $action];
        };

        $high = collect($zones['rows'])->where('level', 'high')->values();
        if ($high->isNotEmpty()) {
            $add('critical', 'Charge', $high->count() === 1 ? "{$high[0]['name']} : {$high[0]['per_courier_day']} livraisons par livreur et par jour" : "{$high->count()} zones en surcharge",
                $names($high, fn ($z) => "{$z['name']} ({$z['per_courier_day']} par livreur et par jour, {$z['couriers']} livreur(s))").". Moyenne des zones : {$zones['average']}. Renforcer ces zones ou recruter.",
                '/admin/statistiques?onglet=zones', 'Voir les zones');
        }
        $gaps = $couriers->where('cash_gap', '<', 0)->sortBy('cash_gap')->values();
        if ($gaps->isNotEmpty()) {
            $add('critical', 'Caisse', "{$money($gaps->sum('cash_gap'))} d'écarts de caisse chez {$gaps->count()} livreur(s)",
                $names($gaps, fn ($c) => "{$c['name']} ({$money($c['cash_gap'])})").'.', '/admin/caisse', 'Voir la caisse');
        }
        $support = $couriers->where('verdict', 'support')->sortBy('success_rate')->values();
        if ($support->isNotEmpty()) {
            $add('warning', 'Livreurs', $support->count() === 1 ? "{$support[0]['name']} est à accompagner" : "{$support->count()} livreurs à accompagner",
                $names($support, fn ($c) => "{$c['name']} ({$c['success_rate']} % de réussite, {$c['per_day']} livraisons par jour)").'.',
                '/admin/statistiques?onglet=couriers', 'Voir les livreurs');
        }
        $slow = collect($staff)->filter(fn ($s) => $s['assign_minutes'] !== null && $s['assigned'] >= 20 && $s['assign_minutes'] > 30)->sortByDesc('assign_minutes')->values();
        if ($slow->isNotEmpty()) {
            $add('warning', 'Bureau', "Répartition : {$slow[0]['assign_minutes']} min en moyenne pour affecter une course",
                $names($slow, fn ($s) => "{$s['name']} ({$s['assigned']} missions affectées, {$s['assign_minutes']} min)").'.', '/admin', 'Voir le poste de pilotage');
        }
        $low = collect($zones['rows'])->where('level', 'low')->values();
        if ($low->isNotEmpty() && $high->isNotEmpty()) {
            $add('info', 'Charge', $low->count() === 1 ? "{$low[0]['name']} est en sous-charge" : "{$low->count()} zones en sous-charge",
                $names($low, fn ($z) => "{$z['name']} ({$z['per_courier_day']} par livreur et par jour)").'. Des livreurs peuvent être déplacés vers les zones en surcharge.',
                '/admin/statistiques?onglet=zones', 'Voir les zones');
        }
        $praise = $couriers->where('verdict', 'praise')->sortByDesc('per_day')->values();
        if ($praise->isNotEmpty()) {
            $add('good', 'Livreurs', $praise->count() === 1 ? "{$praise[0]['name']} est à féliciter" : "{$praise->count()} livreurs à féliciter",
                $names($praise, fn ($c) => "{$c['name']} ({$c['success_rate']} % de réussite, {$c['per_day']} livraisons par jour)").'.',
                '/admin/statistiques?onglet=couriers', 'Voir les livreurs');
        }

        return $out;
    }

    /** Moyenne arrondie des valeurs mesurées (null : aucune mesure). @param Collection<int, int|null> $values */
    private function average(Collection $values): ?int
    {
        $measured = $values->filter(fn ($v) => $v !== null);

        return $measured->isEmpty() ? null : (int) round($measured->avg());
    }

    /** Médiane du trajet (moins sensible aux oublis que la moyenne). @param Collection<int, int|null> $values */
    private function median(Collection $values): ?int
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->sort()->values();
        $n = $sorted->count();

        return $n === 0 ? null : (int) round($n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $tz, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay()->utc(), CarbonImmutable::parse($to->toDateString(), $tz)->endOfDay()->utc()];
    }
}
