<?php

namespace App\Services\Assignment;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierRule;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\Zone;
use App\Services\Finance\CourierCash;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Livraison du jour même : la course peut-elle partir seule, et chez quel livreur ?
 *
 * La course : colis récupéré ou au dépôt (ou reporté à aujourd'hui), à livrer aujourd'hui avant l'heure limite,
 * zone connue et pas une expédition, téléphone valable, montant à encaisser sous le plafond, pas de retour demandé,
 * tentatives pas épuisées.
 *
 * Le livreur : en service (disponible, pas absent, avant sa fin de service), véhicule adapté au colis, sous sa
 * capacité (colis en cours, argent en main), qui n'a pas refusé la course. Ordre : celui qui a le colis (ou le
 * ramasseur, si l'entreprise le veut), le planning des livraisons, puis la zone principale avant la zone de
 * secours, celui qui livre déjà dans le secteur, le moins chargé.
 */
class DeliveryChooser
{
    private const READY = [OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::Rescheduled];

    public function __construct(
        private readonly CourierPlanning $planning,
        private readonly CourierCash $cash,
    ) {}

    /**
     * Pourquoi la course ne part pas seule (null : elle peut partir).
     */
    public function blocker(Order $order, Company $company, CarbonImmutable $now): ?string
    {
        $today = $now->toDateString();
        $cutoff = $company->cutoffFor($today);
        $zone = $order->delivery_zone_id ? Zone::withoutGlobalScopes()->find($order->delivery_zone_id) : null;

        return match (true) {
            ! in_array($order->status, self::READY, true) => 'colis pas encore prêt',
            $order->assignments()->active()->whereIn('type', [AssignmentType::Delivery->value, AssignmentType::Return->value])->exists() => 'déjà confiée',
            (bool) $order->return_requested => 'retour demandé',
            $order->attempts_count >= $order->max_attempts => 'tentatives épuisées',
            ($order->delivery_scheduled_date?->toDateString() ?? $today) > $today => 'prévue un autre jour',
            $cutoff !== null && $now->format('H:i') >= $cutoff => "après l'heure limite ({$cutoff})",
            $zone === null => 'zone inconnue',
            (bool) $zone->is_shipping => 'expédition',
            ! PhoneNumber::isValid($order->recipient_phone) => 'téléphone du destinataire à vérifier',
            $company->auto_delivery_max_cod !== null && (int) $order->cod_amount > $company->auto_delivery_max_cod => 'montant à encaisser au-dessus du plafond',
            $order->fromWarehouse() && $order->prepared_at === null => 'commande à préparer',
            default => null,
        };
    }

    /**
     * @param  list<int>  $exclude  livreurs écartés (refus, empêchement, injoignable)
     * @return array{courier: Courier, why: string}|null
     */
    public function choose(Order $order, Company $company, CarbonImmutable $now, array $exclude = []): ?array
    {
        $today = $now->toDateString();
        $chain = $this->planning->zoneChain($company->id, $order->delivery_zone_id);
        $candidates = $this->candidates($order, $company, $now, $exclude);
        if ($candidates->isEmpty()) {
            return null;
        }

        // Celui qui a le colis (ou le ramasseur, si l'entreprise le veut) et couvre la zone
        $preferred = $order->held_by_courier_id ?? ($company->same_courier_delivery ? $order->pickup_courier_id : null);
        $mine = $preferred ? $candidates->firstWhere('id', $preferred) : null;
        if ($mine && $this->zoneRank($mine, $chain) !== null) {
            return ['courier' => $mine, 'why' => $order->held_by_courier_id === $mine->id ? 'il a déjà le colis' : 'il a fait le ramassage'];
        }

        // Planning des livraisons
        $plan = $this->planning->resolve($order, CourierRule::DELIVERY, $today, $exclude);
        if ($plan && ($planned = $candidates->firstWhere('id', $plan['courier']->id))) {
            return ['courier' => $planned, 'why' => "planning « {$plan['label']} »".($plan['backup'] ? ' (remplaçant)' : '')];
        }

        // Zone principale, puis déjà dans le secteur, puis le moins chargé
        $parents = Zone::withoutGlobalScopes()->where('company_id', $company->id)->pluck('parent_id', 'id');
        $top = fn (?int $id) => $id ? $this->top($id, $parents) : null;
        $area = $top($order->delivery_zone_id);

        $best = $candidates
            ->map(function (Courier $c) use ($chain, $area, $top) {
                $rank = $this->zoneRank($c, $chain);
                $deliveries = $c->getRelation('activeAssignments');

                return $rank === null ? null : [
                    'courier' => $c,
                    'rank' => $rank,
                    'here' => $deliveries->filter(fn (OrderAssignment $a) => $top($a->order?->delivery_zone_id) === $area)->count(),
                    'load' => $deliveries->count(),
                ];
            })
            ->filter()
            ->sortBy([['rank', 'asc'], [fn ($m) => $m['here'] > 0 ? 0 : 1, 'asc'], ['load', 'asc'], [fn ($m) => $m['courier']->id, 'asc']])
            ->first();
        if ($best === null) {
            return null;
        }

        $zone = $best['courier']->zones->first(fn (Zone $z) => in_array($z->id, $chain, true));
        $why = ($best['rank'] === 0 ? 'zone principale ' : 'zone de secours ').$zone->name;
        if ($best['here'] > 0) {
            $why .= ', déjà '.($best['here'] > 1 ? "{$best['here']} livraisons" : '1 livraison').' dans le secteur';
        }
        $why .= ', '.($best['load'] ? "{$best['load']} colis en cours" : 'aucun colis en cours');

        return ['courier' => $best['courier'], 'why' => $why];
    }

    /**
     * Livreurs en service, au véhicule adapté et sous leur capacité.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, Courier>
     */
    private function candidates(Order $order, Company $company, CarbonImmutable $now, array $exclude): Collection
    {
        $absent = [...$this->planning->absentOn($company->id, $now->toDateString()), ...$exclude];
        $couriers = Courier::forCompany($company->id)->where('is_available', true)
            ->with(['user.company', 'zones', 'activeAssignments' => fn ($q) => $q->where('type', AssignmentType::Delivery->value)
                ->with(['order' => fn ($o) => $o->withoutGlobalScopes()->select('id', 'delivery_zone_id', 'cod_amount')])])
            ->get()
            ->filter(fn (Courier $c) => $this->planning->available($c, $absent)
                && ($c->shift_ends_at === null || $now->format('H:i') < $c->shift_ends_at)
                && $this->fits($c, $order)
                && ($c->max_parcels === null || $c->getRelation('activeAssignments')->count() < $c->max_parcels));

        // Argent en main : ce qu'il doit déjà à la caisse, plus ce qu'il encaissera sur ses livraisons en cours et celle-ci
        $capped = $couriers->filter(fn (Courier $c) => $c->max_cash !== null);
        if ($capped->isNotEmpty()) {
            $couriers = $couriers->filter(fn (Courier $c) => $c->max_cash === null
                || $this->cash->balance($c)['due'] + $c->getRelation('activeAssignments')->sum(fn ($a) => (int) $a->order?->cod_amount) + (int) $order->cod_amount <= $c->max_cash);
        }

        return $couriers->values();
    }

    /** Véhicule adapté : pas de grand colis à pied, pas de très grand colis à vélo */
    private function fits(Courier $courier, Order $order): bool
    {
        $weight = (float) ($order->weight_kg ?? 0);
        $huge = $order->package_size === 'XL' || $weight > 20;
        $large = $huge || $order->package_size === 'L' || $weight > 5;

        return match ($courier->vehicle_type) {
            VehicleType::Pieton => ! $large,
            VehicleType::Velo => ! $huge,
            default => true,
        };
    }

    /**
     * 0 : zone principale, 1 : zone de secours, null : il ne dessert pas la zone (ni sa commune).
     *
     * @param  list<int>  $chain
     */
    private function zoneRank(Courier $courier, array $chain): ?int
    {
        $served = $courier->zones->filter(fn (Zone $z) => in_array($z->id, $chain, true));
        if ($served->isEmpty()) {
            return null;
        }

        return $served->contains(fn (Zone $z) => ! $z->pivot->is_backup) ? 0 : 1;
    }

    /**
     * @param  Collection<int, int|null>  $parents
     */
    private function top(int $id, Collection $parents): int
    {
        for ($i = 0; ($parents[$id] ?? null) !== null && $i < 6; $i++) {
            $id = (int) $parents[$id];
        }

        return $id;
    }
}
