<?php

namespace Database\Seeders;

use App\Models\IncidentReason;
use Illuminate\Database\Seeder;

/**
 * Motifs communs (company_id null) : non-livraison et ramassage (e-commerce, distribution et MAD), notes de suivi de
 * l'équipe. Lancé à chaque déploiement : n'ajoute que les motifs manquants, ne touche jamais à ceux que
 * l'administrateur a réglés (Réglages › Motifs).
 */
class IncidentReasonsSeeder extends Seeder
{
    private const DIST = ['distribution', 'mad'];

    public function run(): void
    {
        // [code, libellé, étape, famille, activités, options]
        $reasons = [
            // E-commerce : livraison impossible maintenant (nouvelle tentative)
            ['unreachable', 'Le client ne répond pas au téléphone', 'delivery', 'unavailable', ['ecommerce'], ['counts_as_attempt' => true]],
            ['absent', 'Le client est absent à l\'adresse', 'delivery', 'unavailable', ['ecommerce'], ['counts_as_attempt' => true]],
            ['wrong_address', 'Je ne trouve pas l\'adresse', 'delivery', 'address', ['ecommerce'], ['counts_as_attempt' => true, 'destination_effect' => 'check_address']],
            ['wrong_number', 'Le numéro du client est faux', 'delivery', 'address', ['ecommerce'], ['counts_as_attempt' => true, 'destination_effect' => 'check_phone']],
            ['no_money', 'Le client n\'a pas l\'argent', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true]],
            ['inaccessible', 'Zone inaccessible (route coupée, pluie…)', 'delivery', 'address', ['ecommerce'], []],
            ['refuses_location', 'Le client refuse de donner sa position', 'delivery', 'address', ['ecommerce'], ['counts_as_attempt' => true]],
            // Autre date : report (à valider)
            ['postponed', 'Le client demande à être livré un autre jour', 'delivery', 'postponed', ['ecommerce'], ['requires_date' => true]],
            ['travelling', 'Le client est en voyage, pas en ville', 'delivery', 'postponed', ['ecommerce'], ['requires_date' => true, 'note_hint' => 'Date de retour annoncée, précisions']],
            ['other_address_later', 'Le client veut être livré à une autre adresse un autre jour', 'delivery', 'postponed', ['ecommerce'], ['requires_date' => true, 'requires_note' => true, 'note_hint' => 'La nouvelle adresse']],
            // Le client ne veut pas du colis : retour au marchand (à valider)
            ['refused', 'Le client refuse le colis', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true, 'note_hint' => 'Raison donnée par le client']],
            ['recipient_cancelled', 'Le client annule la commande', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['not_ordered', 'Le client dit ne pas avoir commandé', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true, 'requires_note' => true]],
            ['not_compliant', 'Le produit ne correspond pas à la commande', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true, 'photo' => 'required']],
            ['already_received', 'Le client a déjà reçu ce colis (commande en double)', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['price_disputed', 'Le client conteste le prix', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'requires_note' => true, 'note_hint' => 'Prix annoncé par le client']],
            // Problème grave
            ['unsafe', 'Client agressif, situation dangereuse', 'both', 'other', null, ['requires_note' => true]],
            ['fraud', 'Je soupçonne une fraude (faux billets…)', 'delivery', 'other', ['ecommerce'], ['requires_note' => true]],
            ['parcel_missing', 'Le colis a disparu, ou on me l\'a volé', 'delivery', 'other', null, ['requires_note' => true]],
            ['other', 'Autre (préciser dans la note)', 'both', 'other', ['ecommerce'], ['counts_as_attempt' => true, 'requires_note' => true]],
            // Toutes activités
            ['damaged', 'Le colis est abîmé', 'both', 'other', null, ['photo' => 'required', 'triggers_return' => true, 'requires_note' => true, 'note_hint' => 'Abîmé chez le marchand ou pendant le transport ?']],
            // Ramassage chez le marchand
            ['merchant_unavailable', 'Le marchand est absent ou ne répond pas', 'pickup', 'pickup', null, []],
            ['package_not_ready', 'Le colis n\'est pas prêt', 'pickup', 'pickup', null, []],
            ['p_packaging', 'L\'emballage est insuffisant : je ne ramasse pas', 'pickup', 'pickup', null, ['photo' => 'optional']],
            ['p_cancelled', 'Le marchand annule', 'pickup', 'pickup', null, []],
            ['p_mismatch', 'Le colis ne correspond pas à ce qui a été déclaré (poids, taille)', 'pickup', 'info', null, ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Ce qui ne correspond pas']],

            // Consignes du client : la course continue, rien ne change de statut
            ['i_time_slot', 'Le client veut être livré à une heure précise', 'delivery', 'info', null, ['effect' => 'note', 'asks' => 'delivery_time', 'visible_to_client' => true]],
            ['i_call_back', 'Le client demande qu\'on le rappelle', 'delivery', 'info', null, ['effect' => 'note', 'asks' => 'time', 'visible_to_client' => true]],
            ['i_waiting', 'Le client est en route, j\'attends sur place', 'delivery', 'info', null, ['effect' => 'note', 'visible_to_client' => true]],
            ['i_third_party', 'Le client veut que le colis soit remis à quelqu\'un d\'autre (gardien, voisin, collègue)', 'delivery', 'info', null, ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Nom de la personne', 'visible_to_client' => true]],
            ['i_meeting_point', 'Le client donne un autre lieu de rencontre, tout près', 'delivery', 'info', null, ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Le lieu de rencontre', 'visible_to_client' => true]],
            ['i_landmark', 'Le client donne un repère pour trouver l\'adresse', 'both', 'info', null, ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Le repère']],

            // Distribution et MAD : indisponible (nouvelle tentative)
            ['d_unreachable', 'Injoignable', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_absent', 'Destinataire absent', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_annual_leave', 'En congé annuel', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_travelling', 'En déplacement', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_inventory', 'En inventaire', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_meeting', 'En réunion', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_not_today', 'Ne reçoit pas ce jour', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_closed', 'Fermé à l\'arrivée', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true, 'photo' => 'optional']],
            ['d_closed_works', 'Fermé pour travaux', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true, 'photo' => 'optional']],
            // Report : une date, pas une tentative
            ['d_postponed_recipient', 'Reporté par le destinataire', 'delivery', 'postponed', self::DIST, ['requires_date' => true]],
            ['d_postponed_client', 'Reporté par le client', 'delivery', 'postponed', self::DIST, ['requires_date' => true]],
            // Adresse, contact : le client corrige
            ['d_address_not_found', 'Adresse non retrouvée', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_address']],
            ['d_relocated', 'Délocalisée', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_address']],
            ['d_inaccessible', 'Zone inaccessible', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true]],
            ['d_refuses_location', 'Refus de se localiser', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true]],
            ['d_wrong_number', 'Numéro incorrect', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_phone']],
            // Documents : le pli revient au client (compté dans son forfait), la course peut être reconduite
            ['d_no_purchase_order', 'Absence de bon de commande', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_delivery_note', 'Absence de bon de livraison', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_document_error', 'Erreur sur le document', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_wrong_ncc', 'NCC incorrecte', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_vat', 'Absence de la TVA', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_vat_not_due', 'Ne doit pas inclure la TVA', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_exemption', 'Absence d\'attestation d\'exonération', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_wrong_amount', 'Montant incorrect', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_stamp', 'Absence de cachet', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_signature', 'Absence de signature', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_send_fne', 'Transmettre en FNE', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_send_email', 'Transmettre par email', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            // Destinataire
            ['d_wrong_name', 'Erreur sur le nom du destinataire', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_name']],
            ['d_renamed', 'Changement de dénomination', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_name']],
            ['d_contract_ended', 'Contrat résilié', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_company_gone', 'Entreprise n\'existe plus', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true, 'destination_effect' => 'inactive']],
            ['d_refused', 'Refus de réceptionner', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_already_received', 'Facture déjà réceptionnée', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_other', 'Autres', 'delivery', 'other', self::DIST, ['counts_as_attempt' => true, 'requires_note' => true]],

            // Notes de suivi de l'équipe (toutes activités)
            ['f_waiting_address', 'En attente de l\'adresse', 'follow_up', null, null, ['holds' => true]],
            ['f_processing', 'En traitement', 'follow_up', null, null, ['holds' => true]],
            ['f_contract_ended', 'Contrat résilié', 'follow_up', null, null, []],
            ['f_travelling', 'En déplacement', 'follow_up', null, null, []],
            ['f_refuses_location', 'Refus de se localiser', 'follow_up', null, null, []],
            ['f_renamed', 'Changement de dénomination', 'follow_up', null, null, ['destination_effect' => 'check_name']],
            ['f_company_gone', 'Entreprise n\'existe plus', 'follow_up', null, null, ['destination_effect' => 'inactive']],
            ['f_unreachable', 'Injoignable', 'follow_up', null, null, []],
            ['f_receives_on', 'Réceptionne le', 'follow_up', null, null, ['requires_date' => true]],
            ['f_other', 'Autres', 'follow_up', null, null, ['requires_note' => true]],
        ];

        foreach ($reasons as $i => [$code, $label, $stage, $family, $activities, $options]) {
            IncidentReason::firstOrCreate(['company_id' => null, 'code' => $code], [
                'label' => $label,
                'applies_to' => $stage,
                'family' => $family,
                'activities' => $activities,
                'requires_date' => false,
                'counts_as_attempt' => false,
                'triggers_return' => false,
                // Report ou retour signalé par le livreur : validé par l'équipe ou le client avant de prendre effet
                'needs_validation' => in_array($stage, ['delivery', 'both'], true)
                    && (($options['requires_date'] ?? false) || ($options['triggers_return'] ?? false)),
                'sort_order' => $i,
                ...$options,
            ]);
        }

        // Motifs d'avant les familles : leur famille, si personne ne l'a encore réglée
        foreach ($reasons as [$code, , , $family]) {
            IncidentReason::whereNull('company_id')->where('code', $code)->whereNull('family')->update(['family' => $family]);
        }
    }
}
