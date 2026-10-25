<?php

namespace App\Services\Couriers;

use App\Enums\Activity;
use App\Enums\AssignmentType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;

/**
 * Ce que chaque livreur fait, en plus des réglages de l'entreprise (fiche du livreur ; vide : comme l'entreprise) :
 * encaisser l'argent des colis, la mise à disposition, l'intérieur, le mode hors connexion, voir son gain prévu.
 * Un livreur qui ne fait pas une chose ne reçoit pas ces courses (assignation à la main ou automatique).
 */
class CourierCapabilities
{
    public const KEYS = ['collect_cash', 'mad', 'interior', 'offline', 'see_gain'];

    /** @return array<string, string> libellés (fiche du livreur) */
    public static function labels(): array
    {
        return [
            'collect_cash' => 'Encaisse l\'argent des colis',
            'mad' => 'Fait la mise à disposition (MAD)',
            'interior' => 'Fait l\'intérieur (expéditions)',
            'offline' => 'Peut travailler hors connexion',
            'see_gain' => 'Voit son gain prévu sur chaque mission',
        ];
    }

    /** @return array<string, bool> */
    public function for(Courier $courier, ?Company $company = null): array
    {
        $company ??= $courier->relationLoaded('user') && $courier->user?->relationLoaded('company') ? $courier->user->company : Company::query()->find($courier->company_id);
        $defaults = [
            'collect_cash' => true, 'mad' => true, 'interior' => true,
            'offline' => (bool) ($company?->offline_courier_actions ?? false), 'see_gain' => true,
        ];
        $own = array_filter((array) ($courier->capabilities ?? []), fn ($v) => $v !== null);

        return [...$defaults, ...array_map('boolval', array_intersect_key($own, $defaults))];
    }

    /** Pourquoi ce livreur ne peut pas prendre cette course (null : il peut) */
    public function refusal(Courier $courier, Order $order, AssignmentType $type): ?string
    {
        $can = $this->for($courier);
        $name = $courier->user?->name ?? 'Ce livreur';

        return match (true) {
            ! $can['mad'] && $order->activity === Activity::Mad => "{$name} ne fait pas la mise à disposition.",
            ! $can['interior'] && $order->is_shipping && $type !== AssignmentType::Pickup => "{$name} ne fait pas l'intérieur.",
            ! $can['collect_cash'] && $type === AssignmentType::Delivery && (int) $order->cod_amount > 0 => "{$name} n'encaisse pas d'argent : la course est à encaisser.",
            default => null,
        };
    }

    /**
     * Livreurs de l'entreprise qui ne peuvent pas prendre cette course (exclus de l'assignation automatique).
     *
     * @return list<int>
     */
    public function unableFor(Order $order, AssignmentType $type): array
    {
        return Courier::withoutGlobalScopes()->where('company_id', $order->company_id)->whereNotNull('capabilities')->with('user')->get()
            ->filter(fn (Courier $c) => $this->refusal($c, $order, $type) !== null)->modelKeys();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>|null
     */
    public static function clean(array $input): ?array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out === [] ? null : $out;
    }
}
