<?php

namespace App\Services\Reports;

use App\Models\Company;

/**
 * Réglages du tableau de bord de l'équipe et du point du jour (Réglages › Courses et livraison) :
 * - cartes « À faire maintenant » masquées ;
 * - lots de distribution signalés quand leur échéance arrive dans moins de N heures ;
 * - montants dans le point du jour envoyé sur WhatsApp (seulement pour qui voit la caisse).
 */
class DashboardSettings
{
    /** Cartes « À faire maintenant », dans l'ordre d'affichage */
    public const CARDS = [
        'cutoff' => 'Heure limite dépassée',
        'unassigned' => 'Sans livreur',
        'to_confirm' => 'À valider',
        'to_approve' => 'À approuver par le chef de service',
        'untreated' => 'Courses non traitées',
        'internal_late' => 'Délai interne dépassé',
        'lots_due' => 'Lots à échéance proche',
        'mad_uncovered' => 'Contrats MAD sans livreur',
        'invoices_to_issue' => 'Factures à émettre',
        'to_return' => 'À retourner',
        'failed' => 'Échec de livraison',
        'on_hold' => 'En attente',
        'follow_up' => 'Suivi',
        'to_prepare' => 'À préparer',
        'code_to_verify' => 'Codes à vérifier',
    ];

    public const DEFAULTS = ['hidden_cards' => [], 'lot_due_hours' => 48, 'share_amounts' => true];

    /** @return array{hidden_cards: list<string>, lot_due_hours: int, share_amounts: bool} */
    public function for(?Company $company): array
    {
        return [...self::DEFAULTS, ...self::clean((array) ($company?->dashboard_settings ?? []))];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'dashboard_settings' => ['sometimes', 'nullable', 'array:hidden_cards,lot_due_hours,share_amounts'],
            'dashboard_settings.hidden_cards' => ['nullable', 'array'],
            'dashboard_settings.hidden_cards.*' => ['string', 'in:'.implode(',', array_keys(self::CARDS))],
            'dashboard_settings.lot_due_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'dashboard_settings.share_amounts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        if (array_key_exists('hidden_cards', $input)) {
            $out['hidden_cards'] = array_values(array_intersect(array_keys(self::CARDS), (array) $input['hidden_cards']));
        }
        if (isset($input['lot_due_hours']) && is_numeric($input['lot_due_hours'])) {
            $out['lot_due_hours'] = max(0, min(720, (int) $input['lot_due_hours']));
        }
        if (array_key_exists('share_amounts', $input) && $input['share_amounts'] !== null) {
            $out['share_amounts'] = (bool) $input['share_amounts'];
        }

        return $out;
    }
}
