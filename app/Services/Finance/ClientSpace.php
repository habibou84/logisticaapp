<?php

namespace App\Services\Finance;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Enums\ClientInvoiceStatus;
use App\Enums\NotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppTemplate;
use App\Models\BillingPlan;
use App\Models\ClientCreditNote;
use App\Models\ClientInvoice;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ClientBillingAlert;
use App\Services\Assignment\CourierPlanning;
use App\Services\Messaging\Messenger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Espace des clients sous formule (distribution, MAD) : forfait du mois, livreurs attitrés,
 * factures à payer, et leurs notifications (cloche toujours ; push et WhatsApp selon ses réglages).
 */
class ClientSpace
{
    /** Seuils d'alerte de consommation du forfait, en % */
    private const THRESHOLDS = [100, 80];

    public function __construct(
        private readonly ClientInvoicing $invoicing,
        private readonly CourierPlanning $planning,
        private readonly Messenger $messenger,
    ) {}

    /**
     * Tableau de bord du client : une carte par formule en cours, factures à payer, livreurs MAD.
     *
     * @return array<string, mixed>
     */
    public function summary(Merchant $merchant): array
    {
        $plans = [];
        foreach ([Activity::Distribution, Activity::Mad] as $activity) {
            if ($merchant->hasActivity($activity) && ($usage = $this->usage($merchant, $activity))) {
                $plans[] = $usage;
            }
        }

        $unpaid = ClientInvoice::withoutGlobalScopes()->where('merchant_id', $merchant->id)
            ->where('status', ClientInvoiceStatus::Issued->value)->orderBy('due_at')->get();

        return [
            'activities' => array_map(fn (Activity $a) => ['value' => $a->value, 'label' => $a->label()], $merchant->activityList()),
            'plans' => $plans,
            // Lots ponctuels en cours : avancement
            'lots' => $merchant->hasActivity(Activity::Distribution)
                ? BillingPlan::openLotsFor($merchant)->map(fn (BillingPlan $lot) => [...$lot->brief(), ...$this->lotProgress($lot)])->values()->all()
                : [],
            'invoices' => [
                'to_pay' => (int) $unpaid->sum(fn (ClientInvoice $i) => $i->balance()),
                'overdue' => (int) $unpaid->filter->isOverdue()->sum(fn (ClientInvoice $i) => $i->balance()),
                'count' => $unpaid->count(),
                'next_due' => $unpaid->first()?->due_at?->toDateString(),
            ],
            'couriers' => $merchant->hasActivity(Activity::Mad) ? $this->couriers($merchant) : [],
        ];
    }

    /**
     * Consommation du mois en cours d'une formule : envois (ou courses) inclus et utilisés, extras et
     * estimation de la prochaine facture.
     *
     * @return array<string, mixed>|null
     */
    public function usage(Merchant $merchant, Activity $activity, ?CarbonImmutable $now = null): ?array
    {
        $company = $merchant->company;
        $now ??= CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        $plan = BillingPlan::activeFor($merchant, $activity, $now);
        if ($plan === null) {
            return null;
        }

        [$from, $to] = $this->invoicing->bounds($company, $now);
        $orders = $this->invoicing->orders($company->id, $merchant->id, $activity, $from, $to)->get();
        $lines = $this->invoicing->lines($merchant, $activity, $from, $to);
        $hasForfait = $plan->formula->hasForfait();
        $used = $hasForfait ? (int) $orders->where('billing_plan_id', $plan->id)->where('billing_basis', 'forfait')->sum(fn (Order $o) => max(1, (int) $o->billing_units)) : null;
        $included = $hasForfait ? (int) $plan->included_quantity : null;

        return [
            ...$plan->brief(),
            'activity_label' => $activity->label(),
            'month_label' => ucfirst($from->locale('fr')->translatedFormat('F Y')),
            'unit_label' => $plan->formula->unitLabel(),
            'monthly_fee' => $hasForfait ? (int) $plan->monthly_fee : null,
            'overage_price' => $hasForfait ? (int) $plan->overage_price : null,
            'included' => $included,
            'used' => $used,
            'remaining' => $hasForfait ? max(0, $included - $used) : null,
            'over' => $hasForfait ? max(0, $used - $included) : null,
            'percent' => $hasForfait && $included > 0 ? (int) min(999, round(100 * $used / $included)) : null,
            'orders' => $orders->count(),
            // Ce que coûterait la facture si le mois s'arrêtait aujourd'hui (forfait compris)
            'estimate' => (int) array_sum(array_column($lines, 'amount')),
            // Suppléments du mois (compris dans l'estimation)
            'supplements' => (int) array_sum(array_column(array_filter($lines, fn ($l) => $l['kind'] === 'supplement'), 'amount')),
        ];
    }

    /**
     * Avancement d'un lot ponctuel : colis créés sur le nombre prévu, livrés, en cours, en retard (délai interne),
     * colis en plus, et ce que le lot coûte à ce jour (prix du lot ou prix unitaires, et colis en plus).
     *
     * @return array<string, mixed>
     */
    public function lotProgress(BillingPlan $lot): array
    {
        $orders = Order::withoutGlobalScopes()->where('billing_plan_id', $lot->id)->whereNull('deleted_at')
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->get(['id', 'status', 'billing_amount', 'internal_due_at']);
        $count = $orders->count();
        $quantity = (int) $lot->lot_quantity;
        $delivered = $orders->where('status', OrderStatus::Delivered)->count();
        $finished = $orders->filter(fn (Order $o) => $o->status->isFinal())->count();

        return [
            'used' => $count,
            'included' => $quantity,
            'remaining' => max(0, $quantity - $count),
            'over' => max(0, $count - $quantity),
            'percent' => $quantity > 0 ? (int) min(999, round(100 * $count / $quantity)) : null,
            'delivered' => $delivered,
            'in_progress' => $count - $finished,
            'late' => $orders->filter(fn (Order $o) => ! $o->status->isFinal() && $o->internal_due_at?->isPast())->count(),
            // Tout est créé et terminé : le lot peut être clos
            'done' => $count >= $quantity && $finished === $count,
            'orders' => $count,
            'estimate' => (int) ($lot->lot_price ?? 0) + (int) $orders->sum('billing_amount'),
        ];
    }

    /**
     * Livreurs mis à disposition : nom, téléphone et état du moment (pas de position).
     *
     * @return list<array{id: int, name: string, phone: string|null, status: string, status_label: string}>
     */
    public function couriers(Merchant $merchant): array
    {
        $plan = BillingPlan::activeFor($merchant, Activity::Mad);
        if ($plan === null || $plan->formula !== BillingFormula::MadContract) {
            return [];
        }

        $plan->load(['couriers.user.company', 'couriers.activeAssignments']);
        $today = CarbonImmutable::now($merchant->company->timezone ?: config('app.timezone'))->toDateString();
        $absent = $this->planning->absentOn($merchant->company_id, $today);

        return $plan->couriers->map(function (Courier $c) use ($absent) {
            $status = match (true) {
                in_array($c->id, $absent, true) => 'absent',
                ! $this->planning->available($c, $absent) => 'unavailable',
                $c->activeAssignments->isNotEmpty() => 'busy',
                default => 'available',
            };

            return [
                'id' => $c->id,
                'name' => (string) $c->user?->name,
                'phone' => $c->user?->phone,
                'status' => $status,
                'status_label' => ['absent' => 'Absent aujourd\'hui', 'unavailable' => 'Indisponible', 'busy' => 'En course', 'available' => 'Disponible'][$status],
            ];
        })->sortBy('name')->values()->all();
    }

    /**
     * Facture émise : le client est prévenu.
     */
    public function invoiceIssued(ClientInvoice $invoice): void
    {
        $merchant = $invoice->merchant;
        $period = $invoice->period->locale('fr')->translatedFormat('F Y');
        $due = $invoice->due_at?->format('d/m/Y') ?? '—';

        $this->notify($merchant, NotificationEvent::InvoiceIssued, WhatsAppTemplate::InvoiceIssued,
            [$merchant->business_name, (string) $invoice->number, $invoice->activity->label(), $period, Money::format($invoice->total), $due],
            new ClientBillingAlert('invoice_issued', "📄 Facture {$invoice->number}", "{$invoice->activity->label()} · {$period} : ".Money::format($invoice->total).", à payer avant le {$due}.", "/marchand/factures/{$invoice->id}"));
    }

    /**
     * Avoir émis : le client est prévenu dans l'application (cloche).
     */
    public function creditNoteIssued(ClientCreditNote $note): void
    {
        $invoice = $note->invoice()->withoutGlobalScopes()->first();
        $refund = $note->refund_due > 0 ? ' · '.Money::format($note->refund_due).' à vous rembourser' : '';
        $users = User::withoutGlobalScopes()->where('merchant_id', $note->merchant_id)->where('status', 'active')->get();
        Notification::send($users, new ClientBillingAlert('credit_note_issued', "🧾 Avoir {$note->number}",
            'Sur la facture '.$invoice?->number.' : '.Money::format($note->total).$refund.'.', "/marchand/factures/{$note->client_invoice_id}"));
    }

    /**
     * Rappels du jour : trois jours avant l'échéance (une fois), puis le lendemain de l'échéance et
     * chaque semaine tant que la facture n'est pas payée.
     */
    public function remind(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        $invoices = ClientInvoice::withoutGlobalScopes()->with(['merchant', 'company'])
            ->where('status', ClientInvoiceStatus::Issued->value)->whereNotNull('due_at')->get();

        foreach ($invoices as $invoice) {
            $today = $now->setTimezone($invoice->company?->timezone ?: config('app.timezone'))->startOfDay();
            $due = CarbonImmutable::parse($invoice->due_at->toDateString(), $today->timezone);
            $balance = Money::format($invoice->balance());
            $dueLabel = $due->format('d/m/Y');

            if ($due->greaterThanOrEqualTo($today) && $today->diffInDays($due) <= 3 && $invoice->reminded_at === null) {
                $this->notify($invoice->merchant, NotificationEvent::InvoiceReminder, WhatsAppTemplate::InvoiceReminder,
                    [$invoice->merchant->business_name, (string) $invoice->number, $dueLabel, $balance],
                    new ClientBillingAlert('invoice_reminder', "⏰ Facture {$invoice->number}", "À payer avant le {$dueLabel} : reste {$balance}.", "/marchand/factures/{$invoice->id}"));
                $invoice->forceFill(['reminded_at' => $now])->save();
                $sent++;
            } elseif ($due->lessThan($today) && ($invoice->overdue_notified_at === null || $invoice->overdue_notified_at->lte($now->subDays(7)))) {
                $this->notify($invoice->merchant, NotificationEvent::InvoiceReminder, WhatsAppTemplate::InvoiceOverdue,
                    [$invoice->merchant->business_name, (string) $invoice->number, $dueLabel, $balance],
                    new ClientBillingAlert('invoice_overdue', "⚠️ Facture {$invoice->number} en retard", "Elle était à payer le {$dueLabel} : reste {$balance}.", "/marchand/factures/{$invoice->id}"));
                $invoice->forceFill(['overdue_notified_at' => $now])->save();
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Nouvelle course comptée dans un forfait : alerte au client à 80 % puis à 100 % (une fois par mois).
     */
    public function afterOrder(Order $order): void
    {
        if ($order->billing_plan_id === null || $order->billing_basis !== 'forfait') {
            return;
        }

        try {
            $plan = BillingPlan::withoutGlobalScopes()->find($order->billing_plan_id);
            $merchant = $order->merchant;
            if ($plan === null || ! $plan->formula->hasForfait() || $plan->included_quantity <= 0) {
                return;
            }

            $usage = $this->usage($merchant, $plan->activity);
            if ($usage === null || $usage['id'] !== $plan->id) {
                return;
            }
            $month = CarbonImmutable::now($merchant->company->timezone ?: config('app.timezone'))->format('Y-m');
            $reached = collect(self::THRESHOLDS)->first(fn (int $t) => $usage['percent'] >= $t);
            if ($reached === null) {
                return;
            }

            // Déjà prévenu ce mois-ci pour ce seuil (ou un seuil plus haut)
            [$lastMonth, $lastThreshold] = array_pad(explode(':', (string) $plan->usage_alert), 2, null);
            if ($lastMonth === $month && (int) $lastThreshold >= $reached) {
                return;
            }
            $plan->forceFill(['usage_alert' => "{$month}:{$reached}"])->save();

            $this->notify($merchant, NotificationEvent::ForfaitUsage, WhatsAppTemplate::ForfaitUsage,
                [$merchant->business_name, $plan->formula->label(), mb_strtolower($usage['month_label']), "{$reached} %", (string) $usage['used'], (string) $usage['included'], Money::format((int) $plan->overage_price)],
                new ClientBillingAlert('forfait_usage', $reached >= 100 ? '📦 Forfait du mois épuisé' : "📦 Forfait utilisé à {$reached} %",
                    "{$plan->formula->label()} : {$usage['used']} sur {$usage['included']} inclus. Au-delà : ".Money::format((int) $plan->overage_price).' chacun.', '/marchand'));
        } catch (Throwable $e) {
            // Une alerte manquée ne doit jamais empêcher la course
            Log::warning('Alerte de forfait non envoyée', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $params
     */
    private function notify(Merchant $merchant, NotificationEvent $event, WhatsAppTemplate $template, array $params, ClientBillingAlert $alert): void
    {
        $users = User::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('status', 'active')->get();
        Notification::send($users, $alert);
        $this->messenger->toMerchant($merchant, $event, $template, $params);
    }
}
