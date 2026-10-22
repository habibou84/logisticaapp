<?php

namespace App\Services\Assignment;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CourierRule;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Assignation automatique des ramassages selon le planning des livreurs.
 *
 * À la validation d'une course (et toutes les cinq minutes pour rattraper), le ramassage va au livreur
 * du planning ce jour-là, ou à son remplaçant. À l'essai, rien n'est envoyé : le choix est noté sur la course.
 * Sans règle qui couvre la course, rien ne change : elle reste à l'équipe (alertes « sans livreur »).
 * Un livreur qui refuse, rend la mission ou reste injoignable est écarté pour cette course : son remplaçant la reçoit.
 * Automatisation suspendue (bouton du planning) : plus rien n'est confié.
 * Livraison du jour même (réglage à part) : le colis prêt va au livreur choisi par DeliveryChooser.
 * Ne fait jamais échouer la création ou la validation d'une course.
 */
class AutoAssigner
{
    public const SOURCE_PLANNING = 'planning';

    /** Livraison du jour même choisie automatiquement (zones, capacité, planning) */
    public const SOURCE_AUTO = 'auto';

    public function __construct(
        private readonly CourierPlanning $planning,
        private readonly DeliveryChooser $chooser,
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderJournal $journal,
    ) {}

    /**
     * @param  string|null  $after  pourquoi la course revient (refus, empêchement, livreur injoignable) : noté sur la course
     */
    public function pickup(Order $order, ?string $after = null): ?OrderAssignment
    {
        try {
            return $this->tryPickup($order, $after);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Livraison du jour même : le colis prêt va au livreur choisi (voir DeliveryChooser), ou le choix est noté
     * à l'essai. Ne fait jamais échouer l'action qui l'a déclenchée.
     *
     * @param  string|null  $after  pourquoi la course revient (refus, empêchement, livreur injoignable)
     */
    public function delivery(Order $order, ?string $after = null): ?OrderAssignment
    {
        try {
            return $this->tryDelivery($order, $after);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Rattrapage : courses validées encore sans ramasseur (règle créée ou livreur revenu entre-temps),
     * colis prêts à livrer aujourd'hui encore sans livreur.
     */
    public function run(): int
    {
        $assigned = 0;

        Company::query()->where('status', 'active')->whereIn('auto_delivery', [Company::AUTO_TRIAL, Company::AUTO_ON])
            ->whereNull('automation_paused_at')
            ->each(function (Company $company) use (&$assigned) {
                $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
                Order::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->whereIn('status', [OrderStatus::PickedUp->value, OrderStatus::AtHub->value, OrderStatus::Rescheduled->value])
                    ->where('return_requested', false)
                    ->dueBy($today)
                    ->whereDoesntHave('assignments', fn ($q) => $q->active()->whereIn('type', [AssignmentType::Delivery->value, AssignmentType::Return->value]))
                    ->orderBy('id')
                    ->limit(500)
                    ->get()
                    ->each(function (Order $order) use (&$assigned) {
                        if ($this->delivery($order)) {
                            $assigned++;
                        }
                    });
            });

        Company::query()->where('status', 'active')->whereIn('auto_pickup', [Company::AUTO_TRIAL, Company::AUTO_ON])
            ->whereNull('automation_paused_at')
            ->each(function (Company $company) use (&$assigned) {
                Order::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('status', OrderStatus::Confirmed->value)
                    ->whereNull('pickup_hub_id')
                    ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Pickup->value))
                    ->orderBy('confirmed_at')
                    ->limit(500)
                    ->get()
                    ->each(function (Order $order) use (&$assigned) {
                        if ($this->pickup($order)) {
                            $assigned++;
                        }
                    });
            });

        return $assigned;
    }

    /**
     * Livreurs écartés pour cette course : ceux qui ont refusé ou rendu le ramassage, ou étaient injoignables.
     *
     * @return list<int>
     */
    public static function excluded(Order $order, AssignmentType $type): array
    {
        return $order->assignments()->where('type', $type->value)
            ->where(fn ($q) => $q->where('status', AssignmentStatus::Refused->value)->orWhereNotNull('unreachable_at'))
            ->pluck('courier_id')->unique()->values()->all();
    }

    private function tryDelivery(Order $order, ?string $after): ?OrderAssignment
    {
        $company = Company::query()->find($order->company_id);
        $mode = $company?->auto_delivery ?? Company::AUTO_OFF;
        if ($company === null || $mode === Company::AUTO_OFF || $company->automationPaused()) {
            return null;
        }

        $order = $order->fresh();
        $now = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        if ($this->chooser->blocker($order, $company, $now) !== null) {
            return null;
        }
        $choice = $this->chooser->choose($order, $company, $now, self::excluded($order, AssignmentType::Delivery));
        if ($choice === null) {
            return null;
        }

        $who = $choice['courier']->user->name;
        $why = $choice['why'].($after ? " ({$after})" : '');

        if ($mode === Company::AUTO_TRIAL) {
            if (Cache::add("auto-delivery-trial:{$order->id}:{$now->toDateString()}", 1, now()->addDay())) {
                $this->journal->record($order, null, OrderEventType::Note, [
                    'note' => "Essai de l'assignation automatique : la livraison aurait été confiée à {$who} : {$why}.",
                    'visible_to_merchant' => false,
                    'meta' => ['auto_trial' => 'delivery', 'courier_id' => $choice['courier']->id],
                ]);
            }

            return null;
        }

        $actor = User::forCompany($company->id)->where('status', 'active')->role(Role::Admin->value)->first();
        if ($actor === null) {
            return null;
        }

        try {
            $assignment = $this->dispatcher->assign($actor, $order, AssignmentType::Delivery, $choice['courier'], self::SOURCE_AUTO);
        } catch (BusinessRuleException) {
            return null;
        }

        $this->journal->record($order->fresh(), null, OrderEventType::Note, [
            'note' => "Livraison confiée automatiquement à {$who} : {$why}.",
            'visible_to_merchant' => false,
            'meta' => ['auto' => 'delivery', 'courier_id' => $choice['courier']->id],
        ]);

        return $assignment;
    }

    private function tryPickup(Order $order, ?string $after = null): ?OrderAssignment
    {
        $company = Company::query()->find($order->company_id);
        $mode = $company?->auto_pickup ?? Company::AUTO_OFF;
        if ($company === null || $mode === Company::AUTO_OFF || $company->automationPaused()) {
            return null;
        }

        $order = $order->fresh();
        if ($order->status !== OrderStatus::Confirmed || $order->fromWarehouse() || $order->merchant_id === null
            || $order->assignments()->active()->where('type', AssignmentType::Pickup->value)->exists()) {
            return null;
        }

        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
        $choice = $this->planning->resolve($order, CourierRule::PICKUP, $today, self::excluded($order, AssignmentType::Pickup));
        if ($choice === null) {
            return null;
        }

        $who = $choice['courier']->user->name.($choice['backup'] ? ' (remplaçant)' : '');
        $why = "planning « {$choice['label']} »".($after ? " ({$after})" : '');

        // Essai : on note ce qui aurait été fait, une fois par jour et par course
        if ($mode === Company::AUTO_TRIAL) {
            if (Cache::add("auto-pickup-trial:{$order->id}:{$today}", 1, now()->addDay())) {
                $this->journal->record($order, null, OrderEventType::Note, [
                    'note' => "Essai de l'assignation automatique : le ramassage aurait été confié à {$who}, {$why}.",
                    'visible_to_merchant' => false,
                    'meta' => ['auto_trial' => 'pickup', 'courier_id' => $choice['courier']->id],
                ]);
            }

            return null;
        }

        $actor = User::forCompany($company->id)->where('status', 'active')->role(Role::Admin->value)->first();
        if ($actor === null) {
            return null;
        }

        try {
            $assignment = $this->dispatcher->assign($actor, $order, AssignmentType::Pickup, $choice['courier'], self::SOURCE_PLANNING);
        } catch (BusinessRuleException) {
            return null;
        }

        $this->journal->record($order, null, OrderEventType::Note, [
            'note' => "Ramassage confié automatiquement à {$who}, {$why}.",
            'visible_to_merchant' => false,
            'meta' => ['auto' => 'pickup', 'courier_id' => $choice['courier']->id, 'rule_id' => $choice['rule']->id],
        ]);

        return $assignment;
    }
}
