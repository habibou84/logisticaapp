<?php

namespace App\Services\Couriers;

use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Colis d'une mission ramassage : selon le réglage de l'entreprise (ou le choix du dispatch),
 *  - « garde » : le livreur garde les colis jusqu'à leur jour de livraison ; ils ne sont pas
 *    « en retard » avant ce jour, et la livraison lui est confiée automatiquement ce jour-là ;
 *  - « dépôt » : il les rapporte au dépôt le soir même (règle habituelle des colis chez les
 *    livreurs). Passé l'heure de fermeture du dépôt, il les garde jusqu'au matin.
 */
class PickupCustody
{
    /** Statuts d'un colis gardé, pas encore parti en livraison */
    public const KEPT_STATUSES = [OrderStatus::PickupAssigned, OrderStatus::PickupInProgress, OrderStatus::PickedUp, OrderStatus::DeliveryAssigned];

    public function __construct(
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderJournal $journal,
    ) {}

    public function setting(Company $company): string
    {
        return $company->pickup_mission_custody ?: Company::CUSTODY_CHOOSE;
    }

    /** Le dépôt est-il fermé à cette heure (heure locale de l'entreprise) ? */
    public function depotClosed(Company $company): bool
    {
        return $company->depot_closes_at !== null
            && CarbonImmutable::now($company->timezone ?: config('app.timezone'))->format('H:i') >= $company->depot_closes_at;
    }

    /**
     * Règle d'une nouvelle mission : celle de l'entreprise, ou le choix du dispatch si elle le laisse ouvert.
     *
     * @return array{plan: string, fallback: bool} fallback : dépôt demandé mais fermé, le livreur garde les colis
     */
    public function resolve(Company $company, ?string $requested): array
    {
        $plan = match ($this->setting($company)) {
            Company::CUSTODY_KEEP => Company::CUSTODY_KEEP,
            Company::CUSTODY_DEPOT => Company::CUSTODY_DEPOT,
            default => in_array($requested, [Company::CUSTODY_KEEP, Company::CUSTODY_DEPOT], true)
                ? $requested
                : throw new BusinessRuleException('Indiquez si le livreur garde les colis ou les rapporte au dépôt.', 'custody'),
        };

        if ($plan === Company::CUSTODY_DEPOT && $this->depotClosed($company)) {
            return ['plan' => Company::CUSTODY_KEEP, 'fallback' => true];
        }

        return ['plan' => $plan, 'fallback' => false];
    }

    /**
     * Colis gardés pour un jour de livraison à venir (ou aujourd'hui) : exclus des alertes de garde.
     */
    public static function excludeKeptForLater(Builder $query, Company $company): Builder
    {
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();

        return $query->whereNot(fn (Builder $q) => $q->where('custody_plan', Company::CUSTODY_KEEP)
            ->whereIn('status', OrderStatus::values(self::KEPT_STATUSES))
            ->where(fn (Builder $q) => $q->whereNull('delivery_scheduled_date')->orWhereDate('delivery_scheduled_date', '>=', $today)));
    }

    /** Jour de livraison d'un colis gardé (null s'il n'est pas gardé pour plus tard) */
    public static function keptFor(Order $order): ?string
    {
        if ($order->custody_plan !== Company::CUSTODY_KEEP || ! in_array($order->status, self::KEPT_STATUSES, true)) {
            return null;
        }

        return $order->delivery_scheduled_date?->toDateString() ?? now()->toDateString();
    }

    /**
     * Jour de livraison venu : la livraison des colis gardés est confiée au livreur qui les a.
     *
     * @return int livraisons confiées
     */
    public function assignKeptDeliveries(): int
    {
        $assigned = 0;

        Company::query()->where('status', 'active')->each(function (Company $company) use (&$assigned) {
            $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();

            $orders = Order::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('custody_plan', Company::CUSTODY_KEEP)
                ->where('status', OrderStatus::PickedUp->value)
                ->whereNotNull('held_by_courier_id')
                ->where('return_requested', false)
                ->where(fn (Builder $q) => $q->whereNull('delivery_scheduled_date')->orWhereDate('delivery_scheduled_date', '<=', $today))
                ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Delivery->value))
                ->with(['holder' => fn ($q) => $q->withoutGlobalScopes()->with('user')])
                ->get();
            if ($orders->isEmpty()) {
                return;
            }

            $actor = User::forCompany($company->id)->where('status', 'active')->role(Role::Admin->value)->first();
            if ($actor === null) {
                return;
            }

            foreach ($orders as $order) {
                $courier = $order->holder;
                if (! $courier?->user?->isActive()) {
                    continue;
                }

                try {
                    $this->dispatcher->assign($actor, $order, AssignmentType::Delivery, $courier);
                } catch (BusinessRuleException) {
                    continue;
                }

                $this->journal->record($order->fresh(), null, OrderEventType::Note, [
                    'note' => "Livraison confiée automatiquement à {$courier->user->name}, qui a gardé le colis depuis le ramassage.",
                    'visible_to_merchant' => false,
                ]);
                $assigned++;
            }
        });

        return $assigned;
    }
}
