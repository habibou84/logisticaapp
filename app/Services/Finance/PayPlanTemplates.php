<?php

namespace App\Services\Finance;

use App\Enums\PayCalc;
use App\Enums\PayEvent;
use App\Models\PayPlan;

/**
 * Modèles prêts à l'emploi pour créer un plan de rémunération, à ajuster ensuite.
 */
class PayPlanTemplates
{
    /**
     * @return list<array{key: string, name: string, description: string, plan: array<string, mixed>, rules: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'fixed',
                'name' => 'Fixe par course',
                'description' => 'Un montant fixe pour chaque ramassage, livraison et retour ; une petite somme pour une tentative ratée.',
                'plan' => ['pickup_mode' => PayPlan::PICKUP_PER_PARCEL],
                'rules' => [
                    self::fixed(PayEvent::Pickup, 300),
                    self::fixed(PayEvent::Delivery, 700),
                    self::fixed(PayEvent::FailedAttempt, 200),
                    self::fixed(PayEvent::Return, 300),
                ],
            ],
            [
                'key' => 'percent',
                'name' => 'Pourcentage des frais',
                'description' => 'Le livreur touche une part des frais de livraison, avec un minimum par course ; ramassage payé par passage chez le marchand.',
                'plan' => ['pickup_mode' => PayPlan::PICKUP_PER_VISIT, 'pickup_extra_parcel_amount' => 100, 'min_amount' => 500],
                'rules' => [
                    self::fixed(PayEvent::Pickup, 500),
                    ['event' => PayEvent::Delivery->value, 'calc' => PayCalc::PercentFee->value, 'amount' => 0, 'percent' => 40],
                    self::fixed(PayEvent::FailedAttempt, 200),
                    self::fixed(PayEvent::Return, 300),
                ],
            ],
            [
                'key' => 'zone',
                'name' => 'Par zone',
                'description' => 'Le montant de la livraison dépend de la zone du destinataire : complétez la grille avec vos zones.',
                'plan' => ['pickup_mode' => PayPlan::PICKUP_PER_PARCEL],
                'rules' => [
                    self::fixed(PayEvent::Pickup, 300),
                    ['event' => PayEvent::Delivery->value, 'calc' => PayCalc::ZoneGrid->value, 'amount' => 700, 'zone_amounts' => []],
                    self::fixed(PayEvent::FailedAttempt, 200),
                    self::fixed(PayEvent::Return, 300),
                ],
            ],
            [
                'key' => 'salaried',
                'name' => 'Salarié',
                'description' => 'Un salaire fixe chaque mois, sans paiement par course ; les retenues ne prennent pas plus de 30 % de la paie.',
                'plan' => ['pickup_mode' => PayPlan::PICKUP_PER_PARCEL, 'base_salary' => 100000, 'pay_period' => 'monthly', 'deduction_cap_percent' => 30],
                'rules' => [],
            ],
            [
                'key' => 'mixed',
                'name' => 'Mixte',
                'description' => 'Un salaire de base chaque mois, une prime pour chaque livraison réussie et des primes d\'objectifs (paliers de livraisons, taux de réussite).',
                'plan' => ['pickup_mode' => PayPlan::PICKUP_PER_PARCEL, 'base_salary' => 50000, 'pay_period' => 'monthly', 'deduction_cap_percent' => 30],
                'rules' => [
                    self::fixed(PayEvent::Delivery, 300),
                ],
                'bonuses' => [
                    ['metric' => 'deliveries', 'threshold' => 150, 'amount' => 10000],
                    ['metric' => 'deliveries', 'threshold' => 250, 'amount' => 25000],
                    ['metric' => 'success_rate', 'threshold' => 90, 'min_count' => 50, 'amount' => 5000],
                ],
            ],
            [
                'key' => 'empty',
                'name' => 'Plan vide',
                'description' => 'Aucune règle : pour tout construire vous-même.',
                'plan' => ['pickup_mode' => PayPlan::PICKUP_PER_PARCEL],
                'rules' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixed(PayEvent $event, int $amount): array
    {
        return ['event' => $event->value, 'calc' => PayCalc::Fixed->value, 'amount' => $amount];
    }
}
