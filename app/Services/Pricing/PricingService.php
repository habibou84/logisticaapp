<?php

namespace App\Services\Pricing;

use App\Enums\SurchargeType;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\Zone;

/**
 * Calcule le tarif d'une course :
 * 1. grille négociée du marchand, sinon grille par défaut de l'entreprise ;
 * 2. règle exacte (quartier -> quartier), puis zones parentes (communes),
 *    dans les deux sens si la règle est symétrique ;
 * 3. suppléments (express, fragile, poids).
 */
class PricingService
{
    /**
     * @param  array{is_express?: bool, is_fragile?: bool, weight_kg?: float|null}  $options
     */
    public function quote(Merchant $merchant, Zone $pickupZone, Zone $deliveryZone, array $options = []): Quote
    {
        foreach ($this->gridsFor($merchant) as $grid) {
            $rule = $this->findRule($grid, $pickupZone, $deliveryZone);

            if ($rule !== null) {
                return new Quote(
                    basePrice: $rule->price,
                    surcharges: $this->surcharges($grid, $rule->price, $options),
                    pricingGridId: $grid->id,
                    pricingRuleId: $rule->id,
                );
            }
        }

        throw new BusinessRuleException(
            "Aucun tarif défini pour le trajet {$pickupZone->name} → {$deliveryZone->name}.",
            'delivery_zone_id',
        );
    }

    /**
     * @return list<PricingGrid>
     */
    private function gridsFor(Merchant $merchant): array
    {
        $grids = [];

        if ($merchant->pricing_grid_id !== null) {
            $grids[] = PricingGrid::forCompany($merchant->company_id)->find($merchant->pricing_grid_id);
        }

        $grids[] = PricingGrid::forCompany($merchant->company_id)->where('is_default', true)->first();

        return array_values(array_filter($grids));
    }

    private function findRule(PricingGrid $grid, Zone $origin, Zone $destination): ?PricingRule
    {
        $origins = array_values(array_filter([$origin->id, $origin->parent_id]));
        $destinations = array_values(array_filter([$destination->id, $destination->parent_id]));

        $rules = PricingRule::where('pricing_grid_id', $grid->id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('origin_zone_id', $origins)->whereIn('destination_zone_id', $destinations))
                ->orWhere(fn ($q) => $q->whereIn('origin_zone_id', $destinations)->whereIn('destination_zone_id', $origins)->where('is_symmetric', true)))
            ->get();

        // Couples du plus précis (quartier -> quartier) au plus général (commune -> commune)
        foreach ($origins as $o) {
            foreach ($destinations as $d) {
                $rule = $rules->first(fn (PricingRule $r) => $r->origin_zone_id === $o && $r->destination_zone_id === $d)
                    ?? $rules->first(fn (PricingRule $r) => $r->is_symmetric && $r->origin_zone_id === $d && $r->destination_zone_id === $o);

                if ($rule !== null) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{type: string, label: string, amount: int}>
     */
    private function surcharges(PricingGrid $grid, int $basePrice, array $options): array
    {
        $applied = [];
        $weight = $options['weight_kg'] ?? null;

        foreach ($grid->surcharges as $surcharge) {
            $applies = match ($surcharge->type) {
                SurchargeType::Express => (bool) ($options['is_express'] ?? false),
                SurchargeType::Fragile => (bool) ($options['is_fragile'] ?? false),
                SurchargeType::Weight => $weight !== null
                    && $weight >= ($surcharge->min_value ?? 0)
                    && ($surcharge->max_value === null || $weight < $surcharge->max_value),
            };

            if ($applies) {
                $applied[] = [
                    'type' => $surcharge->type->value,
                    'label' => $surcharge->type->label(),
                    'amount' => $surcharge->amountFor($basePrice),
                ];
            }
        }

        return $applied;
    }
}
