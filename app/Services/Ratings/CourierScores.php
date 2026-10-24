<?php

namespace App\Services\Ratings;

use App\Enums\Activity;
use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\EarningType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierRating;
use App\Models\Order;
use App\Models\OrderAssignment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Note d'un livreur sur 5 : note automatique (faits enregistrés par l'application), moyenne des notes des
 * clients et moyenne des notes de l'équipe, pondérées selon les réglages. Une source sans données (ou
 * pas assez de notes de clients) ne compte pas : les poids des autres sont répartis. Sans aucune source :
 * « nouveau livreur ».
 */
class CourierScores
{
    public function __construct(private readonly RatingSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Courier $courier, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $company = Company::query()->find($courier->company_id);
        $s = $this->settings->for($company);
        $to = CarbonImmutable::instance($to ?? now());
        $from = CarbonImmutable::instance($from ?? $to->subDays((int) $s['window_days']));

        $ratings = CourierRating::withoutGlobalScopes()->where('courier_id', $courier->id)->counted()
            ->whereBetween('created_at', [$from, $to])->get();
        $clients = $ratings->whereIn('source', [CourierRating::CLIENT, CourierRating::RECIPIENT]);
        $team = $ratings->where('source', CourierRating::TEAM);
        $auto = $this->automatic($courier, $from, $to, $s['auto_indicators'], $company);

        $parts = [
            'auto' => ['score' => $auto['score'], 'weight' => (int) $s['weight_auto']],
            'client' => ['score' => $clients->count() >= (int) $s['min_client_ratings'] ? $this->average($clients) : null, 'weight' => (int) $s['weight_client']],
            'team' => ['score' => $team->isNotEmpty() ? $this->average($team) : null, 'weight' => (int) $s['weight_team']],
        ];
        $used = collect($parts)->filter(fn ($p) => $p['score'] !== null && $p['weight'] > 0);
        $total = $used->sum('weight');
        $score = $total > 0 ? round($used->sum(fn ($p) => $p['score'] * $p['weight']) / $total, 1) : null;

        return [
            'score' => $score,
            'new' => $score === null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'auto' => $auto,
            'client' => [
                'average' => $clients->isNotEmpty() ? $this->average($clients) : null,
                'count' => $clients->count(),
                'counted' => $parts['client']['score'] !== null,
                'min' => (int) $s['min_client_ratings'],
                'criteria' => $this->tagCounts($clients, 'criteria'),
                'reasons' => $this->tagCounts($clients, 'reasons'),
            ],
            'team' => [
                'average' => $parts['team']['score'],
                'count' => $team->count(),
                'criteria' => $this->criteriaAverages($team),
            ],
            'weights' => collect($parts)->map(fn ($p, $k) => ['weight' => $p['weight'], 'used' => $used->has($k)])->all(),
            'bad_last_7_days' => $ratings->where('created_at', '>=', $to->subDays(7))->where('stars', '<=', max(1, (int) $s['reason_below']))->count(),
        ];
    }

    /**
     * Note des trois derniers mois, mois par mois (évolution).
     *
     * @return list<array{month: string, score: ?float}>
     */
    public function trend(Courier $courier, int $months = 3): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonths($i);
            $end = $i === 0 ? now() : $start->copy()->endOfMonth();
            $out[] = ['month' => $start->format('Y-m'), 'score' => $this->for($courier, $start, $end)['score']];
        }

        return $out;
    }

    /**
     * Note automatique : moyenne des indicateurs qui ont des données sur la période, ramenée sur 5.
     *
     * @param  list<string>  $enabled
     * @return array{score: ?float, indicators: list<array{key: string, label: string, value: ?int, detail: string}>}
     */
    public function automatic(Courier $courier, CarbonInterface $from, CarbonInterface $to, array $enabled, ?Company $company = null): array
    {
        $assignments = OrderAssignment::query()->where('courier_id', $courier->id)
            ->whereBetween('assigned_at', [$from, $to])->get(['id', 'order_id', 'type', 'status', 'assigned_at', 'completed_at', 'unreachable_at', 'late_alerted_at']);
        // Courses touchées par un événement terrain aux retards excusés (panne, accident…) : ni retard ni échec comptés
        $excused = DB::table('field_event_order')->join('field_events', 'field_events.id', '=', 'field_event_order.field_event_id')
            ->where('field_events.courier_id', $courier->id)->where('field_events.excused', true)
            ->whereIn('field_event_order.order_id', $assignments->pluck('order_id'))->pluck('field_event_order.order_id')->all();
        $deliveries = $assignments->where('type', AssignmentType::Delivery);
        $done = $deliveries->where('status', AssignmentStatus::Completed);
        $failed = $deliveries->where('status', AssignmentStatus::Failed)->whereNotIn('order_id', $excused);
        $pickups = $assignments->where('type', AssignmentType::Pickup)->where('status', AssignmentStatus::Completed)->whereNotIn('order_id', $excused);
        $orders = Order::withoutGlobalScopes()->whereIn('id', $done->pluck('order_id'))
            ->get(['id', 'activity', 'internal_due_at', 'delivered_at', 'delivery_code_unverified_at', 'received_by_name']);
        $incidents = CourierEarning::withoutGlobalScopes()->where('courier_id', $courier->id)
            ->whereIn('type', [EarningType::Shortfall->value, EarningType::LostParcel->value])
            ->whereBetween('created_at', [$from, $to])->count();
        $timed = $orders->filter(fn ($o) => $o->internal_due_at !== null && ! in_array($o->id, $excused, true));
        $billed = $orders->filter(fn ($o) => in_array($o->activity, [Activity::Distribution, Activity::Mad], true));
        $bad = $assignments->filter(fn ($a) => ($a->status === AssignmentStatus::Refused || $a->unreachable_at !== null) && ! in_array($a->order_id, $excused))->count();

        $pct = fn (int $ok, int $all) => $all > 0 ? (int) round(100 * $ok / $all) : null;
        $values = [
            'delivery_success' => [$pct($done->count(), $done->count() + $failed->count()), "{$done->count()} livrée(s), {$failed->count()} échec(s)"],
            'pickup_on_time' => [$pct($pickups->whereNull('late_alerted_at')->count(), $pickups->count()), $pickups->whereNotNull('late_alerted_at')->count()." en retard sur {$pickups->count()}"],
            'internal_delay' => [$pct($timed->filter(fn ($o) => $o->delivered_at && $o->delivered_at->lte($o->internal_due_at))->count(), $timed->count()), "{$timed->count()} course(s) avec délai"],
            'reliability' => [$assignments->isNotEmpty() ? max(0, 100 - $pct($bad, $assignments->count())) : null, "{$bad} refus ou injoignable sur {$assignments->count()}"],
            'cash' => [$assignments->isNotEmpty() || $incidents ? max(0, 100 - 25 * $incidents) : null, "{$incidents} manque(s) ou colis perdu(s)"],
            'delivery_codes' => [$pct($orders->whereNull('delivery_code_unverified_at')->count(), $orders->count()), $orders->whereNotNull('delivery_code_unverified_at')->count().' code(s) à vérifier'],
            'proofs' => [$pct($billed->filter(fn ($o) => filled($o->received_by_name))->count(), $billed->count()), "{$billed->count()} remise(s) distribution ou MAD"],
        ];

        $indicators = collect(RatingCriteria::INDICATORS)->only($enabled)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'value' => $values[$key][0], 'detail' => $values[$key][1]])->values();
        $known = $indicators->whereNotNull('value');

        return [
            'score' => $known->isNotEmpty() ? round($known->avg('value') / 20, 1) : null,
            'indicators' => $indicators->all(),
        ];
    }

    /** Moyenne pondérée des étoiles (une note sur une course à problème compte moins) */
    private function average(Collection $ratings): float
    {
        $weight = $ratings->sum(fn (CourierRating $r) => (float) $r->weight);

        return $weight > 0 ? round($ratings->sum(fn (CourierRating $r) => $r->stars * (float) $r->weight) / $weight, 1) : 0.0;
    }

    /**
     * @return array<string, int>
     */
    private function tagCounts(Collection $ratings, string $field): array
    {
        return $ratings->flatMap(fn (CourierRating $r) => (array) ($r->{$field} ?? []))
            ->filter(fn ($v) => is_string($v))->countBy()->sortDesc()->all();
    }

    /**
     * @return array<string, float>
     */
    private function criteriaAverages(Collection $ratings): array
    {
        return collect(RatingCriteria::TEAM)->keys()
            ->mapWithKeys(fn ($key) => [$key => $ratings->map(fn (CourierRating $r) => $r->criteria[$key] ?? null)->filter()->avg()])
            ->filter()->map(fn ($v) => round($v, 1))->all();
    }
}
