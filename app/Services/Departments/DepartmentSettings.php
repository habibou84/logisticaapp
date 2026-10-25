<?php

namespace App\Services\Departments;

use App\Models\Company;
use App\Models\Merchant;

/**
 * Réglages des départements, au choix de l'administrateur : ceux de l'entreprise (Réglages › Départements),
 * que chaque client peut changer (fiche du client › Départements ; valeur vide : comme l'entreprise).
 */
class DepartmentSettings
{
    /** Demandé : jamais, si la personne le veut, toujours */
    public const LEVELS = ['off', 'optional', 'required'];

    /** Facture : lignes par département sur une facture, une facture par département, sans détail */
    public const INVOICE_MODES = ['detailed', 'split', 'none'];

    /** Forfait du mois : réparti selon les envois de chaque département, ou payé par le département principal */
    public const FORFAIT_SPLITS = ['prorata', 'main'];

    public const DEFAULTS = [
        // Départements proposés pour les clients de distribution et de MAD
        'enabled' => true,
        // Client qui a des départements : chaque course dit lequel l'envoie
        'required' => true,
        // Employés rattachés à des départements : ne voient et ne commandent que pour les leurs
        'staff_own_only' => true,
        // Courrier interne : course vers un autre département du client
        'internal_mail' => true,
        // « Demandé par » (nom de la personne du département)
        'requested_by' => 'optional',
        // Au ramassage : nom (et fonction) de la personne qui remet au livreur, sa signature
        'handover_name' => 'optional',
        'handover_signature' => 'off',
        // À la remise : fonction de la personne qui reçoit (le nom, la signature et la photo suivent la preuve de remise)
        'received_role' => 'optional',
        // Bordereau du jour par département, signé dans l'application ou imprimé
        'slips' => true,
        'invoice_mode' => 'detailed',
        'forfait_split' => 'prorata',
        // Quota du mois d'un département : avertissement à 80 % et 100 %, et nouvelles courses refusées au-delà
        'quota_alerts' => true,
        'quota_block' => false,
        // Le gérant du client crée ses départements et y rattache ses employés (application client)
        'client_manages' => true,
        // Course demandée par un agent : validée d'abord par le chef de son service (ou le gérant) avant d'être envoyée
        'head_approval' => false,
    ];

    /** Réglages que chaque client peut changer */
    public const CLIENT_KEYS = [
        'required', 'staff_own_only', 'internal_mail', 'requested_by', 'handover_name', 'handover_signature',
        'received_role', 'slips', 'invoice_mode', 'forfait_split', 'quota_alerts', 'quota_block', 'client_manages', 'head_approval',
    ];

    /**
     * Réglages de l'entreprise, valeurs par défaut comprises.
     *
     * @return array<string, mixed>
     */
    public function company(?Company $company): array
    {
        $saved = $company?->department_settings ?? [];

        return [...self::DEFAULTS, ...self::clean(is_array($saved) ? $saved : [])];
    }

    /**
     * Réglages d'un client : ceux de l'entreprise, changés par le client.
     *
     * @return array<string, mixed>
     */
    public function for(Merchant $merchant): array
    {
        $company = $merchant->relationLoaded('company') ? $merchant->company : Company::query()->find($merchant->company_id);
        $saved = $merchant->department_settings ?? [];

        return [...$this->company($company), ...self::clean(is_array($saved) ? $saved : [], self::CLIENT_KEYS)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'department_settings'): array
    {
        $bool = ['nullable', 'boolean'];
        $level = ['nullable', 'in:'.implode(',', self::LEVELS)];

        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.enabled" => $bool,
            "{$prefix}.required" => $bool,
            "{$prefix}.staff_own_only" => $bool,
            "{$prefix}.internal_mail" => $bool,
            "{$prefix}.requested_by" => $level,
            "{$prefix}.handover_name" => $level,
            "{$prefix}.handover_signature" => $level,
            "{$prefix}.received_role" => $level,
            "{$prefix}.slips" => $bool,
            "{$prefix}.invoice_mode" => ['nullable', 'in:'.implode(',', self::INVOICE_MODES)],
            "{$prefix}.forfait_split" => ['nullable', 'in:'.implode(',', self::FORFAIT_SPLITS)],
            "{$prefix}.quota_alerts" => $bool,
            "{$prefix}.quota_block" => $bool,
            "{$prefix}.client_manages" => $bool,
            "{$prefix}.head_approval" => $bool,
        ];
    }

    /**
     * Valeurs enregistrées : clés connues, types justes ; vide : comme le niveau au-dessus.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>|null  $keys
     * @return array<string, mixed>
     */
    public static function clean(array $input, ?array $keys = null): array
    {
        $out = [];
        foreach ($keys ?? array_keys(self::DEFAULTS) as $key) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $value = match (true) {
                in_array($key, ['requested_by', 'handover_name', 'handover_signature', 'received_role'], true) => in_array($value, self::LEVELS, true) ? $value : null,
                $key === 'invoice_mode' => in_array($value, self::INVOICE_MODES, true) ? $value : null,
                $key === 'forfait_split' => in_array($value, self::FORFAIT_SPLITS, true) ? $value : null,
                default => (bool) $value,
            };
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
