<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Motifs communs des courses : « destinataire » pour la personne qui reçoit le colis (le « client » est le marchand).
 * Seulement les libellés que l'administrateur n'a pas modifiés ; aucun changement de structure.
 */
return new class extends Migration
{
    /** code => [ancien libellé, nouveau libellé] */
    private const RENAMED = [
        'unreachable' => ['Le client ne répond pas au téléphone', 'Le destinataire ne répond pas au téléphone'],
        'absent' => ['Le client est absent à l\'adresse', 'Le destinataire est absent à l\'adresse'],
        'wrong_number' => ['Le numéro du client est faux', 'Le numéro du destinataire est faux'],
        'no_money' => ['Le client n\'a pas l\'argent', 'Le destinataire n\'a pas l\'argent'],
        'refuses_location' => ['Le client refuse de donner sa position', 'Le destinataire refuse de donner sa position'],
        'postponed' => ['Le client demande à être livré un autre jour', 'Le destinataire demande à être livré un autre jour'],
        'travelling' => ['Le client est en voyage, pas en ville', 'Le destinataire est en voyage, pas en ville'],
        'other_address_later' => ['Le client veut être livré à une autre adresse un autre jour', 'Le destinataire veut être livré à une autre adresse un autre jour'],
        'refused' => ['Le client refuse le colis', 'Le destinataire refuse le colis'],
        'recipient_cancelled' => ['Le client annule la commande', 'Le destinataire annule la commande'],
        'not_ordered' => ['Le client dit ne pas avoir commandé', 'Le destinataire dit ne pas avoir commandé'],
        'already_received' => ['Le client a déjà reçu ce colis (commande en double)', 'Le destinataire a déjà reçu ce colis (commande en double)'],
        'price_disputed' => ['Le client conteste le prix', 'Le destinataire conteste le prix'],
        'i_time_slot' => ['Le client veut être livré à une heure précise', 'Le destinataire veut être livré à une heure précise'],
        'i_call_back' => ['Le client demande qu\'on le rappelle', 'Le destinataire demande qu\'on le rappelle'],
        'i_waiting' => ['Le client est en route, j\'attends sur place', 'Le destinataire est en route, j\'attends sur place'],
        'i_third_party' => ['Le client veut que le colis soit remis à quelqu\'un d\'autre (gardien, voisin, collègue)', 'Le destinataire veut que le colis soit remis à quelqu\'un d\'autre (gardien, voisin, collègue)'],
        'i_meeting_point' => ['Le client donne un autre lieu de rencontre, tout près', 'Le destinataire donne un autre lieu de rencontre, tout près'],
        'i_landmark' => ['Le client donne un repère pour trouver l\'adresse', 'Le destinataire donne un repère pour trouver l\'adresse'],
    ];

    /** Aides à la saisie : ancien texte => nouveau */
    private const HINTS = [
        'Raison donnée par le client' => 'Raison donnée par le destinataire',
        'Prix annoncé par le client' => 'Prix annoncé par le destinataire',
    ];

    public function up(): void
    {
        foreach (self::RENAMED as $code => [$old, $new]) {
            DB::table('incident_reasons')->whereNull('company_id')->where('code', $code)->where('label', $old)->update(['label' => $new]);
        }
        foreach (self::HINTS as $old => $new) {
            DB::table('incident_reasons')->whereNull('company_id')->where('note_hint', $old)->update(['note_hint' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMED as $code => [$old, $new]) {
            DB::table('incident_reasons')->whereNull('company_id')->where('code', $code)->where('label', $new)->update(['label' => $old]);
        }
        foreach (self::HINTS as $old => $new) {
            DB::table('incident_reasons')->whereNull('company_id')->where('note_hint', $new)->update(['note_hint' => $old]);
        }
    }
};
