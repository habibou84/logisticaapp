<?php

namespace App\Services\Vehicles;

use App\Models\Company;
use Illuminate\Support\Str;

/**
 * Réglages de la gestion des véhicules, au choix de l'administrateur (Réglages › Véhicules).
 */
class VehicleSettings
{
    public const DEFAULTS = [
        // État des lieux : chaque jour, chaque lundi ou jamais ; aussi au retour ; obligatoire avant de se mettre en service
        'check_mode' => 'daily',
        'check_end' => false,
        'check_before_service' => true,
        'check_items' => ['brakes', 'tires', 'lights', 'mirrors', 'box'],
        'check_odometer_photo' => true,
        // Un livreur sans véhicule en état (panne, entretien, papiers dépassés) ne reçoit pas de missions
        'no_vehicle_no_missions' => false,
        // Capacité du véhicule (colis) utilisée par la livraison automatique
        'capacity_assignment' => true,
        // Papiers : rappels avant la date (jours) ; véhicule bloqué si un papier obligatoire est dépassé ; permis suivi
        'reminder_days' => [30, 7],
        'block_expired' => true,
        'blocking_documents' => ['insurance', 'inspection'],
        'track_license' => true,
        // Entretien : rappel à moins de n km ou n jours de l'échéance ; plans proposés par type de véhicule
        'maintenance_reminder_km' => 300,
        'maintenance_reminder_days' => 15,
        'default_plans' => VehicleCatalog::PLANS,
        // Carburant : allowance (dotation par jour travaillé) | refund (remboursement des pleins) | courier (à sa charge)
        'fuel_mode' => 'courier',
        'fuel_allowance' => 2000,
        'fuel_allowance_excess_refund' => false,
        'fuel_receipt_required' => true,
        // Alertes : consommation au-delà de n % de la moyenne du véhicule ; écart compteur / GPS au-delà de n %
        'fuel_anomaly_percent' => 30,
        'gps_gap_percent' => 15,
        // Location-vente et location ; rappel au livreur puis alerte au responsable après n jours de retard
        'leases' => true,
        'lease_late_courier_days' => 2,
        'lease_late_manager_days' => 7,
        // Indemnité par jour travaillé pour un livreur qui roule avec son véhicule (0 : aucune)
        'personal_allowance' => 0,
        // Amendes retenues au livreur qui avait le véhicule ; part des dégâts d'un sinistre retenue (plafond par mois)
        'fines_charge_courier' => true,
        'damage_charge_courier' => false,
        'damage_cap_month' => 25000,
        // Panne ou accident signalé (événement terrain) : véhicule « en panne » et réparation au carnet
        'link_field_events' => true,
        // Point de la semaine : échéances et véhicules immobilisés
        'weekly_report' => true,
    ];

    public const CHECK_MODES = ['daily', 'weekly', 'never'];

    public const FUEL_MODES = ['allowance', 'refund', 'courier'];

    /**
     * @return array<string, mixed>
     */
    public function for(?Company $company): array
    {
        $saved = $company?->vehicle_settings ?? [];

        return [...self::DEFAULTS, ...array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $p = 'vehicle_settings.';

        return [
            'vehicle_settings' => ['sometimes', 'nullable', 'array'],
            $p.'check_mode' => ['nullable', 'in:'.implode(',', self::CHECK_MODES)],
            $p.'check_end' => ['nullable', 'boolean'],
            $p.'check_before_service' => ['nullable', 'boolean'],
            $p.'check_items' => ['nullable', 'array'],
            $p.'check_items.*' => ['in:'.implode(',', array_keys(VehicleCatalog::CHECK_ITEMS))],
            $p.'check_odometer_photo' => ['nullable', 'boolean'],
            $p.'no_vehicle_no_missions' => ['nullable', 'boolean'],
            $p.'capacity_assignment' => ['nullable', 'boolean'],
            $p.'reminder_days' => ['nullable', 'array', 'max:3'],
            $p.'reminder_days.*' => ['integer', 'between:1,180'],
            $p.'block_expired' => ['nullable', 'boolean'],
            $p.'blocking_documents' => ['nullable', 'array'],
            $p.'blocking_documents.*' => ['in:'.implode(',', array_keys(VehicleCatalog::DOCUMENTS))],
            $p.'track_license' => ['nullable', 'boolean'],
            $p.'maintenance_reminder_km' => ['nullable', 'integer', 'between:0,10000'],
            $p.'maintenance_reminder_days' => ['nullable', 'integer', 'between:0,120'],
            $p.'default_plans' => ['nullable', 'array'],
            $p.'default_plans.*' => ['array'],
            $p.'default_plans.*.*.key' => ['nullable', 'string', 'max:30'],
            $p.'default_plans.*.*.label' => ['required', 'string', 'max:60'],
            $p.'default_plans.*.*.icon' => ['nullable', 'string', 'max:16'],
            $p.'default_plans.*.*.every_km' => ['nullable', 'integer', 'between:1,500000'],
            $p.'default_plans.*.*.every_months' => ['nullable', 'integer', 'between:1,120'],
            $p.'fuel_mode' => ['nullable', 'in:'.implode(',', self::FUEL_MODES)],
            $p.'fuel_allowance' => ['nullable', 'integer', 'between:0,1000000'],
            $p.'fuel_allowance_excess_refund' => ['nullable', 'boolean'],
            $p.'fuel_receipt_required' => ['nullable', 'boolean'],
            $p.'fuel_anomaly_percent' => ['nullable', 'integer', 'between:5,500'],
            $p.'gps_gap_percent' => ['nullable', 'integer', 'between:5,500'],
            $p.'leases' => ['nullable', 'boolean'],
            $p.'lease_late_courier_days' => ['nullable', 'integer', 'between:0,60'],
            $p.'lease_late_manager_days' => ['nullable', 'integer', 'between:0,120'],
            $p.'personal_allowance' => ['nullable', 'integer', 'between:0,1000000'],
            $p.'fines_charge_courier' => ['nullable', 'boolean'],
            $p.'damage_charge_courier' => ['nullable', 'boolean'],
            $p.'damage_cap_month' => ['nullable', 'integer', 'between:0,10000000'],
            $p.'link_field_events' => ['nullable', 'boolean'],
            $p.'weekly_report' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Valeurs enregistrées : clés connues, valeurs vides retirées (valeur par défaut) ; plans nettoyés.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $clean = array_filter(array_intersect_key($input, self::DEFAULTS), fn ($v) => $v !== null && $v !== '');
        if (isset($clean['default_plans'])) {
            $clean['default_plans'] = collect($clean['default_plans'])->map(function ($items) {
                $items = (array) $items;
                ksort($items);

                return array_values(array_map(fn ($i) => [
                    'key' => ($i['key'] ?? '') ?: Str::slug((string) $i['label'], '_'),
                    'label' => trim((string) $i['label']),
                    'icon' => ($i['icon'] ?? '') ?: '🔧',
                    'every_km' => isset($i['every_km']) && $i['every_km'] !== '' ? (int) $i['every_km'] : null,
                    'every_months' => isset($i['every_months']) && $i['every_months'] !== '' ? (int) $i['every_months'] : null,
                ], $items));
            })->all();
        }
        if (isset($clean['reminder_days'])) {
            $clean['reminder_days'] = collect($clean['reminder_days'])->map(fn ($d) => (int) $d)->filter()->unique()->sortDesc()->values()->all();
        }

        return $clean;
    }
}
