<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\CourierRule;
use App\Services\Reports\DayReport;

/**
 * Réglages qui manquent pour que les courses soient assignées automatiquement aux livreurs, du plus bloquant au moins
 * bloquant (repris de Jibiat), plus les contrats MAD en cours sans livreur désigné. Affichés en bandeau sur la page
 * Courses, chacun avec la page ou le réglage à ouvrir.
 */
class AutoAssignChecks
{
    public function __construct(private readonly DayReport $report) {}

    /** @return list<array{key: string, text: string, why: string, action: string, to: string}> */
    public function missing(Company $company): array
    {
        $items = [];
        $rules = fn () => CourierRule::withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true);
        $pickup = $company->auto_pickup ?? 'off';
        $delivery = $company->auto_delivery ?? 'off';
        $settings = '/admin/reglages/courses';

        if ($company->automationPaused()) {
            $items[] = $this->item('paused', 'Assignation automatique suspendue',
                'Depuis le '.$company->automation_paused_at->format('d/m/Y à H:i').', plus aucune course n\'est assignée automatiquement.', 'Reprendre', '/admin/activite/planning');
        }
        if (! $rules()->exists()) {
            $items[] = $this->item('planning', 'Aucune règle au planning',
                'Le planning dit quel livreur ramasse et livre où : sans lui, aucune course ne peut être assignée automatiquement.', 'Remplir le planning', '/admin/activite/planning');
        }
        if ($pickup === 'off') {
            $items[] = $this->item('auto_pickup', 'Assignation automatique des ramassages désactivée',
                'Chaque ramassage attend qu\'un dispatcher choisisse le livreur.', 'Activer', $settings.'#ramassage-auto');
        } elseif ($pickup === 'trial') {
            $items[] = $this->item('auto_pickup', 'Assignation automatique des ramassages à l\'essai',
                'Le livreur choisi est seulement noté sur la course : rien n\'est encore assigné.', 'Passer en actif', $settings.'#ramassage-auto');
        }
        if ($delivery === 'off') {
            $items[] = $this->item('auto_delivery', 'Assignation automatique des livraisons du jour désactivée',
                'Une fois le colis récupéré, la livraison attend qu\'un dispatcher choisisse le livreur.', 'Activer', $settings.'#livraison-auto');
        } elseif ($delivery === 'trial') {
            $items[] = $this->item('auto_delivery', 'Assignation automatique des livraisons à l\'essai',
                'Le livreur choisi est seulement noté sur la course : rien n\'est encore assigné.', 'Passer en actif', $settings.'#livraison-auto');
        }
        if (($company->validation_mode ?? Company::VALIDATION_MANUAL) === Company::VALIDATION_MANUAL) {
            $items[] = $this->item('validation', 'Validation des courses manuelle',
                'Une course n\'est assignée qu\'après sa validation par un dispatcher.', 'Valider automatiquement', $settings.'#validation');
        }
        if (empty($company->daily_cutoff_time)) {
            $items[] = $this->item('cutoff', 'Heure limite du jour non fixée',
                'Sans elle, une course arrivée tard est assignée pour aujourd\'hui au lieu de demain.', 'Fixer l\'heure limite', $settings.'#journee');
        }
        // MAD : les courses du client vont à ses livreurs réservés ; sans livreur, elles attendent l'équipe
        $uncovered = $this->report->madUncovered($company);
        if ($uncovered !== []) {
            $names = collect($uncovered)->pluck('merchant')->filter()->take(3)->implode(', ');
            $items[] = $this->item('mad', count($uncovered).' contrat'.(count($uncovered) > 1 ? 's' : '').' MAD sans livreur',
                ($names !== '' ? $names.' : ' : '').'aucun livreur n\'est mis à disposition, leurs courses attendent l\'équipe.', 'Désigner les livreurs', '/admin/contrats?activity=mad');
        }

        return $items;
    }

    /** @return array{key: string, text: string, why: string, action: string, to: string} */
    private function item(string $key, string $text, string $why, string $action, string $to): array
    {
        return compact('key', 'text', 'why', 'action', 'to');
    }
}
