<?php

namespace App\Services\Pricing;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Enums\DeliveryDelay;
use App\Enums\ShipmentType;
use App\Exceptions\BusinessRuleException;
use App\Models\BillingPlan;
use App\Models\DistributionRate;
use App\Models\InteriorRate;
use App\Models\Merchant;
use App\Models\Zone;
use App\Support\Money;

/**
 * Tarif d'une course d'un client sous formule (distribution ou MAD). Rien n'est retenu course par course :
 * les frais de la course sont à zéro et la course passe sur la facture du mois, selon sa base :
 *  - forfait : zone couverte par le contrat (communes d'Abidjan, villes de l'intérieur choisies sur le contrat ;
 *    par défaut les communes « Forfait ») : comptée dans le forfait du mois ; au-delà des courses incluses, au
 *    prix de sa destination (prix « en plus » du contrat à Abidjan, tarif de la ville à l'intérieur) ;
 *  - unit : distribution à l'unité sur Abidjan, au prix du délai et du type d'envoi ;
 *  - interior : ville de l'intérieur non couverte, au tarif de la ville (pli, colis, kilogramme en plus) ;
 * Tarifs de l'intérieur et de l'unité : prix négociés du client s'il en a, sinon grille commune.
 *  - cheque : chèque livré, toujours à l'unité ;
 *  - outside : client sous contrat livré hors des communes du forfait, au tarif ordinaire de la course.
 * Au-delà de 5 kg, chaque kilogramme commencé est facturé en plus.
 */
class ClientPricing
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Champs de tarification d'une course de distribution ou de MAD, ou null pour une course e-commerce
     * (tarif ordinaire). Sans formule en cours pour l'activité : la course est refusée.
     *
     * @param  array{shipment_type?: ?string, delivery_delay?: ?string, weight_kg?: mixed, is_express?: bool, is_fragile?: bool}  $data
     * @return array<string, mixed>|null
     */
    public function price(Merchant $merchant, Activity $activity, Zone $pickupZone, Zone $deliveryZone, array $data): ?array
    {
        if (! $activity->isBilled()) {
            return null;
        }
        $plan = BillingPlan::activeFor($merchant, $activity);
        if ($plan === null) {
            throw new BusinessRuleException(
                "Aucune formule {$activity->label()} en cours pour ce client : ajoutez-la sur sa fiche (Formule).",
                'activity',
            );
        }

        $weight = isset($data['weight_kg']) && $data['weight_kg'] !== '' ? (float) $data['weight_kg'] : null;
        $type = $this->type($data['shipment_type'] ?? null, $weight);
        $delay = $plan->formula->hasForfait()
            ? $plan->delivery_delay
            : (DeliveryDelay::tryFrom((string) ($data['delivery_delay'] ?? '')) ?? $plan->delivery_delay);
        $extraKg = $weight !== null && $weight > ShipmentType::COLIS_MAX_KG ? (int) ceil($weight - ShipmentType::COLIS_MAX_KG) : 0;

        // Prix d'une course comptée dans le forfait quand les courses incluses sont épuisées
        $overage = null;
        // Zone couverte par le forfait du contrat (choisie sur le contrat, sinon communes « Forfait »)
        $covered = $plan->formula->hasForfait() && $this->covers($plan, $deliveryZone);
        [$basis, $amount, $label] = match (true) {
            $type === ShipmentType::Cheque => $this->cheque($plan, $delay),
            $covered && $this->isInterior($deliveryZone) => $this->interiorInForfait($plan, $deliveryZone, $type, $delay, $extraKg, $overage),
            $covered => ['forfait', $extraKg * $plan->extra_kg_price, 'Compris dans le forfait du mois'
                .($extraKg > 0 ? " + {$extraKg} kg au-delà de 5 kg (".Money::format($extraKg * $plan->extra_kg_price).')' : '')],
            $this->isInterior($deliveryZone) => $this->interior($plan, $deliveryZone, $type, $delay, $extraKg),
            $plan->formula === BillingFormula::DistributionUnit => $this->unit($plan, $type, $delay, $extraKg),
            default => $this->outside($merchant, $pickupZone, $deliveryZone, $data),
        };
        if ($basis === 'forfait') {
            $overage ??= ['label' => 'Abidjan', 'price' => (int) $plan->overage_price];
        }

        return [
            'delivery_fee' => 0,
            'surcharges_total' => 0,
            'pricing_details' => [
                'billing' => [
                    'formula' => $plan->formula->value,
                    'basis' => $basis,
                    'label' => $label,
                    'amount' => $amount,
                    // Kilogrammes commencés au-delà de 5 kg
                    'extra_kg' => $extraKg,
                    // Course du forfait : son prix si les courses incluses sont épuisées (Abidjan ou ville)
                    ...($overage ? ['overage_label' => $overage['label'], 'overage' => $overage['price']] : []),
                ],
                'surcharges' => [],
            ],
            'is_shipping' => (bool) $deliveryZone->is_shipping,
            'shipment_type' => $type,
            'delivery_delay' => $delay,
            'billing_plan_id' => $plan->id,
            'billing_basis' => $basis,
            'billing_amount' => $amount,
        ];
    }

    /** Pli de plus de 500 g : facturé comme un colis */
    private function type(?string $value, ?float $weight): ShipmentType
    {
        $type = ShipmentType::tryFrom((string) $value) ?? ShipmentType::Colis;

        return $type === ShipmentType::Pli && $weight !== null && $weight > ShipmentType::PLI_MAX_KG ? ShipmentType::Colis : $type;
    }

    /** Hors d'Abidjan : ville de l'intérieur */
    public function isInterior(Zone $zone): bool
    {
        return self::cityKey($zone->city) !== self::cityKey('Abidjan');
    }

    /**
     * Zone couverte par le forfait du contrat : une de ses zones ou un quartier de l'une d'elles. Contrat sans
     * zone choisie : les communes marquées « Forfait » (Réglages › Zones).
     */
    public function covers(BillingPlan $plan, Zone $zone): bool
    {
        $ids = $plan->relationLoaded('zones') ? $plan->zones->modelKeys() : $plan->zones()->pluck('zones.id')->all();
        if ($ids === []) {
            return $this->inForfait($zone);
        }

        // La zone, puis ses parents (quartier → commune → ville)
        for ($id = $zone->id, $depth = 0; $id !== null && $depth < 4; $depth++) {
            if (in_array($id, $ids, true)) {
                return true;
            }
            $id = Zone::withoutGlobalScopes()->whereKey($id)->value('parent_id');
        }

        return false;
    }

    /** Commune du forfait (ou quartier d'une commune du forfait) */
    public function inForfait(Zone $zone): bool
    {
        if ($zone->in_forfait) {
            return true;
        }

        return $zone->parent_id !== null && (bool) Zone::withoutGlobalScopes()->whereKey($zone->parent_id)->value('in_forfait');
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function cheque(BillingPlan $plan, DeliveryDelay $delay): array
    {
        $price = $plan->formula === BillingFormula::DistributionUnit
            ? $this->rate($plan, ShipmentType::Cheque, $delay)
            : $plan->cheque_price;

        return ['cheque', $price, 'Chèque livré'];
    }

    /**
     * Ville de l'intérieur comptée dans le forfait : seulement les kilogrammes au-delà de 5 kg (prix de la
     * ville) ; au-delà des courses incluses, le prix du pli ou du colis de la ville.
     *
     * @param  array{label: string, price: int}|null  $overage
     * @return array{0: string, 1: int, 2: string}
     */
    private function interiorInForfait(BillingPlan $plan, Zone $zone, ShipmentType $type, DeliveryDelay $delay, int $extraKg, ?array &$overage): array
    {
        $rate = $this->interiorRate($plan, $zone, $delay);
        $overage = [
            'label' => "{$type->label()} pour {$rate->city} en {$delay->label()}",
            'price' => (int) ($type === ShipmentType::Pli ? $rate->pli_price : $rate->colis_price),
        ];
        $kg = $extraKg * $rate->extra_kg_price;

        return ['forfait', $kg, "Compris dans le forfait du mois ({$rate->city})"
            .($extraKg > 0 ? " + {$extraKg} kg au-delà de 5 kg (".Money::format($kg).')' : '')];
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function interior(BillingPlan $plan, Zone $zone, ShipmentType $type, DeliveryDelay $delay, int $extraKg): array
    {
        $rate = $this->interiorRate($plan, $zone, $delay);

        $base = $type === ShipmentType::Pli ? $rate->pli_price : $rate->colis_price;
        $amount = $base + $extraKg * $rate->extra_kg_price;

        return ['interior', $amount, "{$type->label()} pour {$rate->city} en {$delay->label()}"
            .($extraKg > 0 ? " + {$extraKg} kg au-delà de 5 kg" : '')];
    }

    private function interiorRate(BillingPlan $plan, Zone $zone, DeliveryDelay $delay): InteriorRate
    {
        // Prix négociés du client, sinon grille commune
        $find = fn (?int $merchantId) => InteriorRate::forCompany($plan->company_id)->grid($merchantId)
            ->where('delivery_delay', $delay->value)->get()
            ->first(fn (InteriorRate $r) => self::cityKey($r->city) === self::cityKey($zone->city));
        $rate = $find($plan->merchant_id) ?? $find(null);
        if ($rate === null) {
            throw new BusinessRuleException(
                "Aucun tarif pour {$zone->city} en {$delay->label()} : ajoutez-le dans Distribution › Tarifs (villes de l'intérieur).",
                'delivery_zone_id',
            );
        }

        return $rate;
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function unit(BillingPlan $plan, ShipmentType $type, DeliveryDelay $delay, int $extraKg): array
    {
        $amount = $this->rate($plan, $type, $delay) + $extraKg * $plan->extra_kg_price;

        return ['unit', $amount, "{$type->label()} en {$delay->label()}"
            .($extraKg > 0 ? " + {$extraKg} kg au-delà de 5 kg" : '')];
    }

    private function rate(BillingPlan $plan, ShipmentType $type, DeliveryDelay $delay): int
    {
        // Prix négociés du client, sinon grille commune
        $find = fn (?int $merchantId) => DistributionRate::forCompany($plan->company_id)->grid($merchantId)
            ->where('delivery_delay', $delay->value)->where('shipment_type', $type->value)->value('price');
        $rate = $find($plan->merchant_id) ?? $find(null);
        if ($rate === null) {
            throw new BusinessRuleException(
                "Prix à l'unité d'un {$type->label()} en {$delay->label()} pas encore fixé : voir Distribution › Tarifs.",
                'delivery_delay',
            );
        }

        return (int) $rate;
    }

    /**
     * Client sous contrat livré hors des communes du forfait (ex. Bingerville) : tarif ordinaire de la course.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function outside(Merchant $merchant, Zone $pickupZone, Zone $deliveryZone, array $data): array
    {
        $quote = $this->pricing->quote($merchant, $pickupZone, $deliveryZone, [
            'is_express' => (bool) ($data['is_express'] ?? false),
            'is_fragile' => (bool) ($data['is_fragile'] ?? false),
            'weight_kg' => isset($data['weight_kg']) ? (float) $data['weight_kg'] : null,
        ]);

        return ['outside', $quote->total(), 'Hors des communes du forfait : '.$deliveryZone->name];
    }

    /** « Bouaké », « bouake », « BOUAKÉ » : la même ville */
    public static function cityKey(?string $city): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim((string) $city))) ?: '';

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }
}
