<?php

namespace App\Services\Finance;

use App\Enums\AssignmentType;
use App\Enums\EarningType;
use App\Enums\PayCalc;
use App\Enums\PayEvent;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\Order;
use App\Models\PayPlan;
use App\Models\PayPlanRule;
use App\Models\Zone;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Rémunération des livreurs selon leur plan : à chaque étape d'une course, les
 * règles du plan qui s'appliquent (conditions) donnent chacune un montant (calcul) ;
 * les montants s'additionnent, puis les bornes par course du plan s'appliquent.
 * Chaque gain est enregistré avec sa règle et son détail : changer un plan ne modifie
 * jamais les gains déjà acquis.
 */
class CourierPay
{
    public function __construct(private CourierActivity $activity) {}

    // Ramassage « par passage » : colis du même marchand ramassés dans ce délai
    public const VISIT_WINDOW_HOURS = 3;

    /** @var array<int, Zone|null> */
    private array $zones = [];

    /**
     * Plan qui s'applique au livreur : le sien, sinon celui par défaut de l'entreprise.
     */
    public function planFor(Courier $courier): ?PayPlan
    {
        $plan = $courier->pay_plan_id
            ? PayPlan::forCompany($courier->company_id)->find($courier->pay_plan_id)
            : PayPlan::defaultFor($courier->company_id);

        return $plan?->loadMissing('rules');
    }

    /**
     * Enregistre les gains du livreur pour une étape de la course.
     */
    public function record(?Courier $courier, PayEvent $event, Order $order): void
    {
        if ($courier === null || ($plan = $this->planFor($courier)) === null) {
            return;
        }

        // Tentative ratée : payée une fois par course et par jour
        if ($event === PayEvent::FailedAttempt && CourierEarning::query()->withoutGlobalScopes()
            ->where('courier_id', $courier->id)->where('order_id', $order->id)
            ->where('type', EarningType::FailedAttempt->value)->whereDate('created_at', today())->exists()) {
            return;
        }

        foreach ($this->lines($plan, $event, $order, $courier, withVisits: true) as $line) {
            if ($line['amount'] === 0) {
                continue;
            }

            CourierEarning::create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'type' => $event->earningType(),
                'amount' => $line['amount'],
                'order_id' => $order->id,
                'pay_plan_id' => $plan->id,
                'pay_plan_rule_id' => $line['rule']?->id,
                'description' => $line['label'].' '.$order->tracking_code,
                'detail' => $line['detail'],
            ]);
        }
    }

    /**
     * Gain attendu pour une étape (affiché au livreur sur sa mission).
     */
    public function expected(Courier $courier, PayEvent $event, Order $order): int
    {
        $plan = $this->planFor($courier);

        return $plan ? array_sum(array_column($this->lines($plan, $event, $order, $courier, withVisits: true), 'amount')) : 0;
    }

    /**
     * Gain attendu pour une mission, ou null si la mission ne rapporte rien selon le plan.
     */
    public function expectedFor(Courier $courier, AssignmentType $type, Order $order): ?int
    {
        $event = match ($type) {
            AssignmentType::Pickup => PayEvent::Pickup,
            AssignmentType::Delivery => $order->is_shipping ? PayEvent::Shipping : PayEvent::Delivery,
            AssignmentType::Return => PayEvent::Return,
        };

        return $this->expected($courier, $event, $order) ?: null;
    }

    /**
     * Lignes de gain d'une étape : une par règle applicable, plus l'éventuelle borne.
     * $at : moment de l'étape (conditions de jour et d'heure), maintenant par défaut.
     * $sameVisit : imposé par le simulateur ; sinon déduit des gains déjà enregistrés.
     *
     * @return list<array{rule: ?PayPlanRule, label: string, amount: int, detail: string}>
     */
    public function lines(PayPlan $plan, PayEvent $event, Order $order, Courier $courier, bool $withVisits = false, ?CarbonInterface $at = null, ?bool $sameVisit = null): array
    {
        $at ??= now();

        // Ramassage par passage : les colis suivants du même marchand ne paient que le supplément
        if ($event === PayEvent::Pickup && $plan->pickup_mode === PayPlan::PICKUP_PER_VISIT
            && ($sameVisit ?? ($withVisits && $this->sameVisit($courier, $order)))) {
            return [[
                'rule' => null,
                'label' => 'Colis supplémentaire',
                'amount' => $plan->pickup_extra_parcel_amount,
                'detail' => 'Même passage chez le marchand : colis supplémentaire',
            ]];
        }

        $rules = $plan->rules->where('event', $event);
        // Expédition sans règle propre : règles de la livraison
        if ($rules->isEmpty() && $event === PayEvent::Shipping) {
            $rules = $plan->rules->where('event', PayEvent::Delivery);
        }

        $lines = [];
        foreach ($rules as $rule) {
            if ($this->matches($rule, $event, $order, $courier, $at)) {
                $lines[] = ['rule' => $rule, 'label' => $rule->label ?: $event->label(), ...$this->amount($rule, $event, $order)];
            }
        }

        if ($lines === []) {
            return [];
        }

        $total = array_sum(array_column($lines, 'amount'));
        if ($plan->min_amount !== null && $total < $plan->min_amount) {
            $lines[] = ['rule' => null, 'label' => 'Minimum par course', 'amount' => $plan->min_amount - $total, 'detail' => 'Complément jusqu\'au minimum de '.Money::format($plan->min_amount)];
        } elseif ($plan->max_amount !== null && $total > $plan->max_amount) {
            $lines[] = ['rule' => null, 'label' => 'Plafond par course', 'amount' => $plan->max_amount - $total, 'detail' => 'Ramené au plafond de '.Money::format($plan->max_amount)];
        }

        return $lines;
    }

    /**
     * @return array{amount: int, detail: string}
     */
    private function amount(PayPlanRule $rule, PayEvent $event, Order $order): array
    {
        return match ($rule->calc) {
            PayCalc::Fixed => ['amount' => $rule->amount, 'detail' => 'Montant fixe'],
            PayCalc::PercentFee => $this->percent($rule->percent, $order->totalFees(), 'des frais de livraison'),
            // Avant la livraison (gain attendu) : montant à encaisser prévu
            PayCalc::PercentCollected => $this->percent($rule->percent, (int) ($order->collected_amount ?? $order->cod_amount), 'du montant encaissé'),
            PayCalc::ZoneGrid => $this->grid($rule, $this->zone($event, $order)),
        };
    }

    /**
     * @return array{amount: int, detail: string}
     */
    private function percent(?float $percent, int $base, string $of): array
    {
        $percent = (float) $percent;
        $label = rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');

        return ['amount' => (int) round($base * $percent / 100), 'detail' => "{$label} % de ".Money::format($base)." ({$of})"];
    }

    /**
     * Grille par zone : montant de la zone, sinon de sa commune, sinon montant par défaut.
     *
     * @return array{amount: int, detail: string}
     */
    private function grid(PayPlanRule $rule, ?Zone $zone): array
    {
        $amounts = $rule->zone_amounts ?? [];

        foreach (array_filter([$zone, $zone?->parent]) as $candidate) {
            if (isset($amounts[$candidate->id])) {
                return ['amount' => (int) $amounts[$candidate->id], 'detail' => 'Zone '.$candidate->name];
            }
        }

        return ['amount' => $rule->amount, 'detail' => 'Autres zones'];
    }

    private function matches(PayPlanRule $rule, PayEvent $event, Order $order, Courier $courier, CarbonInterface $at): bool
    {
        $conditions = $rule->conditions ?? [];

        // Jours et heures, dans le fuseau de l'entreprise
        if (! empty($conditions['days']) || ! empty($conditions['time_from'])) {
            $local = CarbonImmutable::parse($at)->setTimezone($this->activity->timezone($courier->company_id));
            if (! empty($conditions['days']) && ! in_array($local->dayOfWeekIso, array_map('intval', $conditions['days']), true)) {
                return false;
            }
            if (! empty($conditions['time_from']) && ! empty($conditions['time_to']) && ! $this->inTimeRange($local->format('H:i'), $conditions['time_from'], $conditions['time_to'])) {
                return false;
            }
        }

        if (! empty($conditions['zone_ids'])) {
            $zone = $this->zone($event, $order);
            if ($zone === null || ! array_intersect([$zone->id, $zone->parent_id], array_map('intval', $conditions['zone_ids']))) {
                return false;
            }
        }

        if (! empty($conditions['express']) && ! $order->is_express) {
            return false;
        }

        if (! empty($conditions['fragile']) && ! $order->is_fragile) {
            return false;
        }

        if (! empty($conditions['vehicle_types']) && ! in_array($courier->vehicle_type?->value, $conditions['vehicle_types'], true)) {
            return false;
        }

        if ($event === PayEvent::FailedAttempt && ! empty($conditions['incident_reason_ids'])
            && ! in_array($order->last_incident_reason_id, array_map('intval', $conditions['incident_reason_ids']), true)) {
            return false;
        }

        return true;
    }

    /**
     * Plage horaire [début, fin[ ; une plage qui passe minuit (22:00 → 06:00) est acceptée.
     */
    private function inTimeRange(string $time, string $from, string $to): bool
    {
        return $from <= $to ? ($time >= $from && $time < $to) : ($time >= $from || $time < $to);
    }

    /**
     * Zone qui compte pour l'étape : ramassage → zone de ramassage, sinon zone de livraison.
     */
    private function zone(PayEvent $event, Order $order): ?Zone
    {
        $id = $event === PayEvent::Pickup ? $order->pickup_zone_id : $order->delivery_zone_id;

        return $this->zones[$id] ??= Zone::withoutGlobalScopes()->with('parent')->find($id);
    }

    /**
     * Un colis du même marchand a déjà été payé au ramassage pour ce livreur récemment.
     */
    private function sameVisit(Courier $courier, Order $order): bool
    {
        return CourierEarning::query()->withoutGlobalScopes()
            ->where('courier_id', $courier->id)
            ->where('type', EarningType::Pickup->value)
            ->where('order_id', '!=', $order->id)
            ->where('created_at', '>=', now()->subHours(self::VISIT_WINDOW_HOURS))
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('merchant_id', $order->merchant_id))
            ->exists();
    }
}
