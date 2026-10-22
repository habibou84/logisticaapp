<?php

namespace App\Services\Pricing;

/**
 * Résultat d'un calcul de tarif.
 */
final readonly class Quote
{
    /**
     * @param  list<array{type: string, label: string, amount: int}>  $surcharges
     */
    public function __construct(
        public int $basePrice,
        public array $surcharges,
        public int $pricingGridId,
        public int $pricingRuleId,
    ) {}

    public function surchargesTotal(): int
    {
        return array_sum(array_column($this->surcharges, 'amount'));
    }

    public function total(): int
    {
        return $this->basePrice + $this->surchargesTotal();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base_price' => $this->basePrice,
            'surcharges' => $this->surcharges,
            'surcharges_total' => $this->surchargesTotal(),
            'total' => $this->total(),
            'pricing_grid_id' => $this->pricingGridId,
            'pricing_rule_id' => $this->pricingRuleId,
        ];
    }
}
