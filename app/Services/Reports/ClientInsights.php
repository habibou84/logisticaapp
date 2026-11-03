<?php

namespace App\Services\Reports;

use App\Enums\ClientInvoiceStatus;
use App\Enums\MerchantStatus;
use App\Models\ClientInvoice;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Clients (Statistiques › Clients avancé) :
 * - fidélité par mois d'arrivée (cohortes) : part des clients arrivés un mois qui envoient encore 1, 2, 3, 6 mois après ;
 * - clients en baisse : 30 derniers jours contre la moyenne mensuelle des 3 mois d'avant, avec téléphone ;
 * - concentration (80/20) : clients qui font 80 % du chiffre d'affaires de la période ;
 * - factures : délai moyen de paiement par client et reste à payer par ancienneté (pas échu, 1-30, 31-60, 61-90, plus de 90 jours de retard).
 */
class ClientInsights
{
    /** Mois après l'arrivée suivis dans les cohortes */
    public const OFFSETS = [1, 2, 3, 6];

    public function __construct(private readonly Profitability $profitability, private readonly StatsSettings $settings) {}

    /** @return array<string, mixed> */
    public function build(Company $company, CarbonImmutable $from, CarbonImmutable $to, bool $withAmounts, ?string $activity = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);
        $orders = fn () => Order::forCompany($company->id)->when($activity, fn ($q) => $q->where('activity', $activity));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'cohorts' => $this->cohorts($orders, $now, $tz),
            'decline' => $this->decline($company, $orders, $now),
            'pareto' => $withAmounts ? $this->pareto($company, $from, $to, $activity) : null,
            'payments' => $withAmounts && in_array($activity, [null, 'distribution', 'mad'], true) ? $this->payments($company, $now, $activity) : null,
        ];
    }

    /**
     * @return list<array{month: string, label: string, size: int, retention: array<int, ?float>}>
     */
    private function cohorts(callable $orders, CarbonImmutable $now, string $tz): array
    {
        $first = $now->startOfMonth()->subMonths(12);
        // Mois de chaque course par client (depuis 18 mois, pour connaître le premier mois de chacun)
        $rows = $orders()->where('created_at', '>=', $first->subMonths(6)->utc())->get(['merchant_id', 'created_at']);
        $months = $rows->groupBy('merchant_id')->map(fn (Collection $c) => $c->map(fn ($o) => CarbonImmutable::parse($o->created_at)->setTimezone($tz)->format('Y-m'))->unique()->values());
        $firstEver = $orders()->whereIn('merchant_id', $months->keys())->selectRaw('merchant_id, MIN(created_at) AS first_at')->groupBy('merchant_id')->pluck('first_at', 'merchant_id')
            ->map(fn ($at) => CarbonImmutable::parse($at, 'UTC')->setTimezone($tz)->format('Y-m'));

        $out = [];
        for ($m = $first; $m->lessThanOrEqualTo($now->startOfMonth()); $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $members = $firstEver->filter(fn ($f) => $f === $key)->keys();
            $retention = [];
            foreach (self::OFFSETS as $offset) {
                $target = $m->addMonths($offset);
                $retention[$offset] = $target->greaterThan($now->startOfMonth()) || $members->isEmpty()
                    ? null
                    : round($members->filter(fn ($id) => $months[$id]->contains($target->format('Y-m')))->count() / $members->count() * 100, 1);
            }
            $out[] = ['month' => $key, 'label' => ucfirst($m->locale('fr')->isoFormat('MMM YYYY')), 'size' => $members->count(), 'retention' => $retention];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decline(Company $company, callable $orders, CarbonImmutable $now): array
    {
        $count = fn (CarbonImmutable $a, CarbonImmutable $b) => $orders()->whereBetween('created_at', [$a->utc(), $b->utc()])
            ->selectRaw('merchant_id, COUNT(*) AS n')->groupBy('merchant_id')->pluck('n', 'merchant_id');
        $last = $count($now->subDays(30), $now);
        $before = $count($now->subDays(120), $now->subDays(30));
        $min = $this->settings->for($company)['alerts']['min_volume'];
        $merchants = Merchant::withoutGlobalScopes()->whereIn('id', $before->keys())->where('status', MerchantStatus::Active->value)
            ->get(['id', 'business_name', 'contact_name', 'phone'])->keyBy('id');

        return $before->map(function ($n, $id) use ($last, $merchants) {
            $avg = round($n / 3, 1);
            $recent = (int) ($last[$id] ?? 0);

            return isset($merchants[$id]) ? [
                'id' => (int) $id, 'name' => $merchants[$id]->business_name, 'contact_name' => $merchants[$id]->contact_name, 'phone' => $merchants[$id]->phone,
                'average' => $avg, 'recent' => $recent, 'change' => $avg > 0 ? (int) round(($recent / $avg - 1) * 100) : null,
            ] : null;
        })->filter(fn ($r) => $r !== null && $r['average'] >= max(1, $min / 2) && $r['change'] !== null && $r['change'] <= -20)
            ->sortBy('change')->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function pareto(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?string $activity): array
    {
        $clients = collect($this->profitability->build($company, $from, $to, $activity)['clients'])->filter(fn ($c) => $c['revenue'] > 0)->sortByDesc('revenue')->values();
        $total = (int) $clients->sum('revenue');
        $running = 0;
        $rows = $clients->map(function ($c) use (&$running, $total) {
            $running += $c['revenue'];

            return ['id' => $c['id'], 'name' => $c['name'], 'revenue' => $c['revenue'], 'share' => $total ? round($c['revenue'] / $total * 100, 1) : 0, 'cumulative' => $total ? round($running / $total * 100, 1) : 0];
        });
        $top80 = $rows->search(fn ($r) => $r['cumulative'] >= 80);

        return [
            'total' => $total,
            'clients' => $rows->count(),
            'clients_for_80' => $top80 === false ? $rows->count() : $top80 + 1,
            'top_share' => $rows->first()['share'] ?? null,
            'top3_share' => $total ? round($rows->take(3)->sum('revenue') / $total * 100, 1) : null,
            'rows' => $rows->take(50)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payments(Company $company, CarbonImmutable $now, ?string $activity): array
    {
        $invoices = ClientInvoice::withoutGlobalScopes()->where('company_id', $company->id)
            ->when($activity, fn ($q) => $q->where('activity', $activity))
            ->whereIn('status', [ClientInvoiceStatus::Issued->value, ClientInvoiceStatus::Paid->value])
            ->with('merchant:id,business_name')->get();
        $buckets = ['not_due' => 0, '0_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $byClient = [];
        foreach ($invoices as $i) {
            $id = $i->merchant_id;
            $byClient[$id] ??= ['id' => $id, 'name' => $i->merchant?->business_name, 'invoices' => 0, 'paid' => 0, 'days' => [], 'balance' => 0, 'overdue' => 0];
            $byClient[$id]['invoices']++;
            if ($i->status === ClientInvoiceStatus::Paid && $i->issued_at && $i->paid_at) {
                $byClient[$id]['paid']++;
                $byClient[$id]['days'][] = (int) $i->issued_at->diffInDays($i->paid_at);
            }
            $balance = $i->balance();
            if ($i->status === ClientInvoiceStatus::Issued && $balance > 0) {
                $byClient[$id]['balance'] += $balance;
                $late = $i->due_at ? (int) max(0, $i->due_at->startOfDay()->diffInDays($now->startOfDay(), false)) : 0;
                if ($late > 0) {
                    $byClient[$id]['overdue'] += $balance;
                }
                $buckets[match (true) {
                    $late === 0 => 'not_due', $late <= 30 => '0_30', $late <= 60 => '31_60', $late <= 90 => '61_90', default => 'over_90',
                }] += $balance;
            }
        }
        $rows = collect($byClient)->map(fn ($c) => [...collect($c)->except('days')->all(), 'average_days' => $c['days'] ? (int) round(array_sum($c['days']) / count($c['days'])) : null])
            ->sortByDesc('balance')->values();
        $allDays = collect($byClient)->flatMap(fn ($c) => $c['days']);

        return [
            'average_days' => $allDays->isEmpty() ? null : (int) round($allDays->avg()),
            'outstanding' => array_sum($buckets),
            'buckets' => $buckets,
            'clients' => $rows->all(),
        ];
    }
}
