<?php

namespace App\Services\Reports;

use App\Enums\Activity;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\ClientReport;
use App\Models\Company;
use App\Models\Department;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderEvent;
use App\Models\User;
use App\Notifications\ClientReportReady;
use App\Services\Couriers\DailyActivity;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Rapports mensuels des clients sous contrat (distribution, MAD) : préparés en brouillon le 1er du mois pour le mois
 * écoulé, relus par l'équipe (mot facultatif), puis envoyés : le client les retrouve dans son espace (Mes rapports)
 * et ses responsables sont prévenus. Sert d'argument commercial avec la facture.
 */
class ClientReports
{
    public function __construct(private readonly ActivityOptions $options) {}

    /** Brouillons du mois (AAAA-MM-01) pour les clients sous contrat qui ont eu des courses ; nombre préparés */
    public function generate(Company $company, CarbonImmutable $month): int
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($month->format('Y-m-01'), $tz)->startOfDay();
        $end = $start->endOfMonth();
        $merchantIds = Order::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereIn('activity', [Activity::Distribution->value, Activity::Mad->value])
            ->whereBetween('created_at', [$start->utc(), $end->utc()])->distinct()->pluck('merchant_id');

        $count = 0;
        foreach (Merchant::withoutGlobalScopes()->whereIn('id', $merchantIds)->get() as $merchant) {
            $report = ClientReport::withoutGlobalScopes()->firstOrNew(['merchant_id' => $merchant->id, 'period' => $start->toDateString()], ['company_id' => $company->id]);
            // Déjà envoyé : on n'y touche plus
            if ($report->exists && $report->status === 'sent') {
                continue;
            }
            $report->fill(['company_id' => $company->id, 'status' => 'draft', 'data' => $this->data($merchant, $start, $end, $tz)])->save();
            $count++;
        }

        return $count;
    }

    /**
     * Chiffres du mois d'un client : courses, plis, livrées à l'heure, accusés, échecs, départements, lots.
     *
     * @return array<string, mixed>
     */
    public function data(Merchant $merchant, CarbonImmutable $start, CarbonImmutable $end, string $tz): array
    {
        $orders = Order::withoutGlobalScopes()->where('merchant_id', $merchant->id)->whereNull('deleted_at')
            ->whereIn('activity', [Activity::Distribution->value, Activity::Mad->value]);
        $created = (clone $orders)->whereBetween('created_at', [$start->utc(), $end->utc()])->get(['id', 'activity', 'shipment_type', 'shipment_quantity', 'department_id', 'status']);
        $delivered = (clone $orders)->where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', [$start->utc(), $end->utc()])
            ->get(['id', 'activity', 'company_id', 'merchant_id', 'billing_plan_id', 'shipment_type', 'shipment_quantity', 'delivery_scheduled_date', 'created_at', 'delivered_at', 'picked_up_at', 'internal_due_at', 'department_id']);
        $onTime = $delivered->filter(fn (Order $o) => $o->internal_due_at
            ? $o->delivered_at->lessThanOrEqualTo($o->internal_due_at)
            : CarbonImmutable::parse($o->delivered_at)->setTimezone($tz)->toDateString() <= ($o->delivery_scheduled_date ?? $o->created_at)->toDateString())->count();
        $withPhoto = $delivered->isEmpty() ? collect() : OrderAttachment::query()->whereIn('order_id', $delivered->pluck('id'))->where('type', 'photo_delivery')->pluck('order_id')->flip();
        $proofAsked = $delivered->filter(fn (Order $o) => $this->options->proof($o)['proof_photo'] ?? false);
        $failures = OrderEvent::query()->whereIn('order_id', (clone $orders)->select('id'))->where('type', OrderEventType::Incident->value)
            ->where('from_status', OrderStatus::OutForDelivery->value)->whereIn('to_status', [OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value])
            ->whereBetween('created_at', [$start->utc(), $end->utc()])->with('incidentReason:id,label')->get(['id', 'incident_reason_id']);
        $departments = Department::withoutGlobalScopes()->where('merchant_id', $merchant->id)->pluck('name', 'id');
        $minutes = $delivered->filter(fn (Order $o) => $o->picked_up_at && $o->delivered_at->greaterThanOrEqualTo($o->picked_up_at))
            ->map(fn (Order $o) => (int) round($o->picked_up_at->diffInMinutes($o->delivered_at)))->sort()->values();

        return [
            'merchant' => $merchant->business_name,
            'month' => $start->format('Y-m'),
            'label' => ucfirst($start->locale('fr')->isoFormat('MMMM YYYY')),
            'created' => $created->count(),
            'units' => (int) $created->sum(fn (Order $o) => DailyActivity::units($o)),
            'delivered' => $delivered->count(),
            'units_delivered' => (int) $delivered->sum(fn (Order $o) => DailyActivity::units($o)),
            'on_time' => $onTime,
            'on_time_rate' => $delivered->count() ? round($onTime / $delivered->count() * 100, 1) : null,
            'receipts' => ['asked' => $proofAsked->count(), 'received' => $proofAsked->filter(fn (Order $o) => isset($withPhoto[$o->id]))->count()],
            'median_minutes' => $minutes->isEmpty() ? null : $minutes[intdiv($minutes->count(), 2)],
            'failures' => $failures->count(),
            'failure_reasons' => $failures->groupBy(fn ($e) => $e->incidentReason?->label ?? 'Sans motif')->map->count()->sortDesc()->take(5)->all(),
            'cancelled' => $created->where('status', OrderStatus::Cancelled)->count(),
            'departments' => $created->whereNotNull('department_id')->groupBy('department_id')
                ->map(fn ($list, $id) => ['name' => $departments[$id] ?? 'Département', 'created' => $list->count(), 'units' => (int) $list->sum(fn (Order $o) => DailyActivity::units($o))])
                ->sortByDesc('created')->values()->all(),
            'lots' => BillingPlan::withoutGlobalScopes()->where('merchant_id', $merchant->id)->lots()->overlapping($start->toDateString(), $end->toDateString())->get()
                ->map(fn (BillingPlan $l) => ['name' => $l->lotName(), 'quantity' => (int) $l->lot_quantity, 'due_on' => $l->lot_due_on?->toDateString(),
                    'delivered' => Order::withoutGlobalScopes()->where('billing_plan_id', $l->id)->where('status', OrderStatus::Delivered->value)->count()])->values()->all(),
        ];
    }

    /**
     * Espace client : livrées dans le délai (e-commerce : le jour prévu ; distribution, MAD : avant le délai interne),
     * et courses par département sur la période.
     *
     * @return array{delivered: int, on_time: int, rate: ?float, departments: list<array{name: string, created: int, units: int}>}
     */
    public function forClientSpace(Merchant $merchant, CarbonImmutable $start, CarbonImmutable $end, ?string $activity = null): array
    {
        $tz = $merchant->company?->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($start->toDateString(), $tz)->startOfDay();
        $end = CarbonImmutable::parse($end->toDateString(), $tz)->endOfDay();
        $orders = fn () => Order::withoutGlobalScopes()->where('merchant_id', $merchant->id)->whereNull('deleted_at')->when($activity, fn ($q) => $q->where('activity', $activity));
        $delivered = $orders()->where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', [$start->utc(), $end->utc()])
            ->get(['delivery_scheduled_date', 'created_at', 'delivered_at', 'internal_due_at']);
        $ok = $delivered->filter(fn (Order $o) => $o->internal_due_at
            ? $o->delivered_at->lessThanOrEqualTo($o->internal_due_at)
            : CarbonImmutable::parse($o->delivered_at)->setTimezone($tz)->toDateString() <= ($o->delivery_scheduled_date ?? $o->created_at)->toDateString())->count();
        $names = Department::withoutGlobalScopes()->where('merchant_id', $merchant->id)->pluck('name', 'id');

        return [
            'delivered' => $delivered->count(),
            'on_time' => $ok,
            'rate' => $delivered->count() ? round($ok / $delivered->count() * 100, 1) : null,
            'departments' => $names->isEmpty() ? [] : $orders()->whereBetween('created_at', [$start->utc(), $end->utc()])->whereNotNull('department_id')
                ->get(['department_id', 'activity', 'shipment_type', 'shipment_quantity'])->groupBy('department_id')
                ->map(fn ($list, $id) => ['name' => $names[$id] ?? 'Département', 'created' => $list->count(), 'units' => (int) $list->sum(fn (Order $o) => DailyActivity::units($o))])
                ->sortByDesc('created')->values()->all(),
        ];
    }

    /** Envoi après relecture : visible dans l'espace client, responsables du client prévenus */
    public function send(ClientReport $report, User $actor, ?string $note = null): ClientReport
    {
        $report->forceFill(['status' => 'sent', 'sent_at' => now(), 'sent_by' => $actor->id, 'note' => $note ?? $report->note])->save();
        $leads = User::query()->where('merchant_id', $report->merchant_id)->where('status', 'active')->get()->filter(fn (User $u) => $u->isMerchantLead());
        Notification::send($leads, new ClientReportReady($report));

        return $report;
    }
}
