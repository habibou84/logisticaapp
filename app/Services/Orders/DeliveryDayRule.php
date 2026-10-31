<?php

namespace App\Services\Orders;

use App\Enums\Activity;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Jour où le livreur peut faire une livraison, selon l'activité (Réglages › Courses et livraison) :
 * - jour même : seulement les livraisons prévues ce jour-là ;
 * - dans le délai : celles du jour, plus celles d'avant encore dans leur délai interne ou dont le lot n'a pas
 *   atteint son échéance (distribution : un lot se distribue sur plusieurs jours) ;
 * - sans limite (MAD : les courses du client se font pendant la mise à disposition).
 * Les retards et les jours à venir se règlent à l'agence (nouvelle date ou annulation). Une action faite hors
 * connexion est jugée au jour où elle a été faite.
 */
class DeliveryDayRule
{
    public const RULES = ['same_day', 'within_delay', 'none'];

    public const DEFAULTS = ['ecommerce' => 'same_day', 'distribution' => 'within_delay', 'mad' => 'none'];

    /** @var array<int, ?string> */
    private array $lotDueOn = [];

    /** @var array<int, ?Company> Entreprise de chaque course, lue une fois (liste des missions) */
    private array $companies = [];

    /** @return array<string, string> */
    public function for(?Company $company): array
    {
        return [...self::DEFAULTS, ...self::clean((array) ($company?->delivery_day_rules ?? []))];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function clean(array $input): array
    {
        return collect($input)->only(array_keys(self::DEFAULTS))->filter(fn ($v) => in_array($v, self::RULES, true))->all();
    }

    public function allows(Order $order, ?CarbonInterface $at = null): bool
    {
        return $this->refusal($order, $at) === null;
    }

    /**
     * Pourquoi le livreur ne peut pas faire cette livraison maintenant (null : il peut).
     */
    public function refusal(Order $order, ?CarbonInterface $at = null): ?string
    {
        $activity = $order->activity instanceof Activity ? $order->activity->value : ($order->activity ?: 'ecommerce');
        $company = $order->relationLoaded('company') ? $order->company
            : ($this->companies[$order->company_id] ??= Company::query()->find($order->company_id));
        $rule = $this->for($company)[$activity] ?? 'same_day';
        if ($rule === 'none') {
            return null;
        }

        $tz = $company?->timezone ?: config('app.timezone');
        $at = Carbon::instance($at ?? now());
        $day = $at->copy()->setTimezone($tz)->toDateString();
        $planned = PickupGroups::deliveryDay($order);

        if ($planned === $day) {
            return null;
        }
        if ($planned < $day && $rule === 'within_delay' && $this->stillInDelay($order, $at, $day)) {
            return null;
        }

        return 'Cette livraison est prévue le '.Carbon::parse($planned)->format('d/m/Y')
            .' : vous ne pouvez faire que les livraisons du jour. Voyez avec l\'agence.';
    }

    // Délai interne pas encore dépassé, ou lot de distribution avant son échéance
    private function stillInDelay(Order $order, CarbonInterface $at, string $day): bool
    {
        if ($order->internal_due_at !== null && $at->lessThanOrEqualTo($order->internal_due_at)) {
            return true;
        }
        if ($order->billing_plan_id === null) {
            return false;
        }
        $due = $this->lotDueOn[$order->billing_plan_id] ??= BillingPlan::withoutGlobalScopes()->lots()->whereKey($order->billing_plan_id)
            ->value('lot_due_on');

        return $due !== null && Carbon::parse($due)->toDateString() >= $day;
    }
}
