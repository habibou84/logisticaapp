<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Zone;
use App\Services\Couriers\DailyActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Analyse croisée (Statistiques › Analyse croisée) : deux axes au choix (client, zone, livreur, activité, jour de la
 * semaine, mois, heure de création) et un indicateur (courses, livrées, échecs, taux de réussite, plis, chiffre
 * d'affaires), sur les courses créées pendant la période. Tableau exportable.
 */
class Pivot
{
    public const DIMENSIONS = [
        'client' => 'Client', 'zone' => 'Zone de livraison', 'courier' => 'Livreur', 'activity' => 'Activité',
        'weekday' => 'Jour de la semaine', 'month' => 'Mois', 'hour' => 'Heure de création',
    ];

    public const METRICS = [
        'courses' => 'Courses', 'delivered' => 'Livrées', 'failed' => 'Échecs (tentatives)', 'success_rate' => 'Taux de réussite',
        'units' => 'Plis et colis', 'revenue' => 'Chiffre d\'affaires',
    ];

    /** Lignes et colonnes gardées au plus (les plus fortes) */
    private const MAX_ROWS = 50;

    private const MAX_COLS = 15;

    private const WEEKDAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    /** @return array<string, mixed> */
    public function build(Company $company, CarbonImmutable $from, CarbonImmutable $to, string $rows, ?string $cols, string $metric, ?string $activity = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay();
        $end = CarbonImmutable::parse($to->toDateString(), $tz)->endOfDay();
        $orders = Order::forCompany($company->id)->when($activity, fn ($q) => $q->where('activity', $activity))
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->get(['id', 'merchant_id', 'delivery_zone_id', 'delivery_courier_id', 'activity', 'status', 'attempts_count', 'created_at',
                'delivery_fee', 'surcharges_total', 'billing_amount', 'shipment_type', 'shipment_quantity']);

        $labels = $this->labels($company, $orders);
        $value = fn (Order $o, string $dim) => match ($dim) {
            'client' => $o->merchant_id,
            'zone' => $o->delivery_zone_id ?? 0,
            'courier' => $o->delivery_courier_id ?? 0,
            'activity' => DailyActivity::activityOf($o),
            'weekday' => CarbonImmutable::parse($o->created_at)->setTimezone($tz)->dayOfWeekIso,
            'month' => CarbonImmutable::parse($o->created_at)->setTimezone($tz)->format('Y-m'),
            'hour' => (int) CarbonImmutable::parse($o->created_at)->setTimezone($tz)->format('G'),
        };
        $label = fn (string $dim, $key) => match ($dim) {
            'client' => $labels['client'][$key] ?? 'Client',
            'zone' => $labels['zone'][$key] ?? 'Sans zone',
            'courier' => $labels['courier'][$key] ?? 'Sans livreur',
            'activity' => ['ecommerce' => 'E-commerce', 'distribution' => 'Distribution', 'mad' => 'MAD'][$key] ?? $key,
            'weekday' => self::WEEKDAYS[$key] ?? $key,
            'month' => ucfirst(CarbonImmutable::parse($key.'-01')->locale('fr')->isoFormat('MMM YYYY')),
            'hour' => "{$key} h",
        };
        $measure = fn (Collection $list) => $this->measure($list, $metric);
        $sortable = in_array($rows, ['weekday', 'month', 'hour'], true);

        $byRow = $orders->groupBy(fn (Order $o) => $value($o, $rows));
        $rowKeys = $sortable ? $byRow->keys()->sort()->values() : $byRow->sortByDesc(fn ($l) => $l->count())->keys()->take(self::MAX_ROWS)->values();
        $colKeys = collect();
        if ($cols) {
            $byCol = $orders->groupBy(fn (Order $o) => $value($o, $cols));
            $colKeys = in_array($cols, ['weekday', 'month', 'hour'], true) ? $byCol->keys()->sort()->values() : $byCol->sortByDesc(fn ($l) => $l->count())->keys()->take(self::MAX_COLS)->values();
        }

        $table = $rowKeys->map(function ($rk) use ($byRow, $cols, $colKeys, $value, $label, $rows, $measure) {
            $list = $byRow[$rk];

            return [
                'key' => (string) $rk,
                'label' => $label($rows, $rk),
                'cells' => $cols ? $colKeys->map(fn ($ck) => $measure($list->filter(fn (Order $o) => (string) $value($o, $cols) === (string) $ck)))->all() : [],
                'total' => $measure($list),
            ];
        })->values();

        return [
            'rows' => $rows, 'cols' => $cols, 'metric' => $metric,
            'columns' => $colKeys->map(fn ($ck) => ['key' => (string) $ck, 'label' => $label($cols, $ck)])->values()->all(),
            'table' => $table->all(),
            'totals' => ['cells' => $cols ? $colKeys->map(fn ($ck) => $measure($orders->filter(fn (Order $o) => (string) $value($o, $cols) === (string) $ck)))->all() : [], 'total' => $measure($orders)],
            'orders' => $orders->count(),
        ];
    }

    /** @param  Collection<int, Order>  $list */
    private function measure(Collection $list, string $metric): int|float|null
    {
        $delivered = $list->where('status', OrderStatus::Delivered);

        return match ($metric) {
            'courses' => $list->count(),
            'delivered' => $delivered->count(),
            'failed' => (int) $list->sum('attempts_count'),
            'success_rate' => ($finished = $list->filter(fn (Order $o) => $o->status->isFinal())->count()) > 0 ? round($delivered->count() / $finished * 100, 1) : null,
            'units' => (int) $list->sum(fn (Order $o) => DailyActivity::units($o)),
            'revenue' => (int) $delivered->sum(fn (Order $o) => DailyActivity::activityOf($o) === 'ecommerce' ? $o->delivery_fee + $o->surcharges_total : (int) $o->billing_amount),
        };
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<string, Collection>
     */
    private function labels(Company $company, Collection $orders): array
    {
        return [
            'client' => Merchant::withoutGlobalScopes()->whereIn('id', $orders->pluck('merchant_id')->unique())->pluck('business_name', 'id'),
            'zone' => Zone::withoutGlobalScopes()->whereIn('id', $orders->pluck('delivery_zone_id')->filter()->unique())->pluck('name', 'id'),
            'courier' => Courier::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('id', $orders->pluck('delivery_courier_id')->filter()->unique())
                ->with('user:id,name')->get()->mapWithKeys(fn (Courier $c) => [$c->id => $c->user?->name]),
        ];
    }
}
