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
            ['unreachable', 'Le destinataire ne répond pas au téléphone', 'delivery', 'unavailable', ['ecommerce'], ['counts_as_attempt' => true]],
            ['absent', 'Le destinataire est absent à l\'adresse', 'delivery', 'unavailable', ['ecommerce'], ['counts_as_attempt' => true]],
            ['wrong_address', 'Je ne trouve pas l\'adresse', 'delivery', 'address', ['ecommerce'], ['counts_as_attempt' => true, 'destination_effect' => 'check_address']],
            ['wrong_number', 'Le numéro du destinataire est faux', 'delivery', 'address', ['ecommerce'], ['counts_as_attempt' => true, 'destination_effect' => 'check_phone']],
            ['no_money', 'Le destinataire n\'a pas l\'argent', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true]],
            ['inaccessible', 'Zone inaccessible (route coupée, pluie…)', 'delivery', 'address', ['ecommerce'], []],
            ['refuses_location', 'Le destinataire refuse de donner sa position', 'delivery', 'address', ['ecommerce'], ['counts_as_attempt' => true]],
            // Autre date : report (à valider)
            ['postponed', 'Le destinataire demande à être livré un autre jour', 'delivery', 'postponed', ['ecommerce'], ['requires_date' => true]],
            ['travelling', 'Le destinataire est en voyage, pas en ville', 'delivery', 'postponed', ['ecommerce'], ['requires_date' => true, 'note_hint' => 'Date de retour annoncée, précisions']],
            ['other_address_later', 'Le destinataire veut être livré à une autre adresse un autre jour', 'delivery', 'postponed', ['ecommerce'], ['requires_date' => true, 'requires_note' => true, 'note_hint' => 'La nouvelle adresse']],
            // Le destinataire ne veut pas du colis : retour au client (à valider)
            ['refused', 'Le destinataire refuse le colis', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true, 'note_hint' => 'Raison donnée par le destinataire']],
            ['recipient_cancelled', 'Le destinataire annule la commande', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['not_ordered', 'Le destinataire dit ne pas avoir commandé', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true, 'requires_note' => true]],
            ['not_compliant', 'Le produit ne correspond pas à la commande', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true, 'photo' => 'required']],
            ['already_received', 'Le destinataire a déjà reçu ce colis (commande en double)', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['price_disputed', 'Le destinataire conteste le prix', 'delivery', 'recipient', ['ecommerce'], ['counts_as_attempt' => true, 'requires_note' => true, 'note_hint' => 'Prix annoncé par le destinataire']],
            // Problème grave
            ['unsafe', 'Client agressif, situation dangereuse', 'both', 'other', null, ['requires_note' => true]],
            ['fraud', 'Je soupçonne une fraude (faux billets…)', 'delivery', 'other', ['ecommerce'], ['requires_note' => true]],
            ['parcel_missing', 'Le colis a disparu, ou on me l\'a volé', 'delivery', 'other', null, ['requires_note' => true]],
            ['other', 'Autre (préciser dans la note)', 'both', 'other', ['ecommerce'], ['counts_as_attempt' => true, 'requires_note' => true]],
            // Toutes activités
            ['damaged', 'Le colis est abîmé', 'both', 'other', null, ['photo' => 'required', 'triggers_return' => true, 'requires_note' => true, 'note_hint' => 'Abîmé chez le marchand ou pendant le transport ?']],
            // Ramassage chez le marchand
            ['merchant_unavailable', 'Le marchand est absent ou ne répond pas', 'pickup', 'pickup', ['ecommerce'], []],
            ['package_not_ready', 'Le colis n\'est pas prêt', 'pickup', 'pickup', ['ecommerce'], []],
            ['p_packaging', 'L\'emballage est insuffisant : je ne ramasse pas', 'pickup', 'pickup', ['ecommerce'], ['photo' => 'optional']],
            ['p_cancelled', 'Le marchand annule', 'pickup', 'pickup', ['ecommerce'], []],
            ['p_mismatch', 'Le colis ne correspond pas à ce qui a été déclaré (poids, taille)', 'pickup', 'info', ['ecommerce'], ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Ce qui ne correspond pas']],

            // Consignes du destinataire : la course continue, rien ne change de statut
            ['i_time_slot', 'Le destinataire veut être livré à une heure précise', 'delivery', 'info', ['ecommerce'], ['effect' => 'note', 'asks' => 'delivery_time', 'visible_to_client' => true]],
            ['i_call_back', 'Le destinataire demande qu\'on le rappelle', 'delivery', 'info', ['ecommerce'], ['effect' => 'note', 'asks' => 'time', 'visible_to_client' => true]],
            ['i_waiting', 'Le destinataire est en route, j\'attends sur place', 'delivery', 'info', ['ecommerce'], ['effect' => 'note', 'visible_to_client' => true]],
            ['i_third_party', 'Le destinataire veut que le colis soit remis à quelqu\'un d\'autre (gardien, voisin, collègue)', 'delivery', 'info', ['ecommerce'], ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Nom de la personne', 'visible_to_client' => true]],
            ['i_meeting_point', 'Le destinataire donne un autre lieu de rencontre, tout près', 'delivery', 'info', ['ecommerce'], ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Le lieu de rencontre', 'visible_to_client' => true]],
            ['i_landmark', 'Le destinataire donne un repère pour trouver l\'adresse', 'both', 'info', ['ecommerce'], ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Le repère']],

            // Distribution et MAD : livraison impossible maintenant (nouvelle tentative)
            ['d_unreachable', 'Le destinataire ne répond pas au téléphone', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_absent', 'Le destinataire est absent', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true]],
            ['d_unavailable_person', 'La personne qui réceptionne n\'est pas disponible', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true, 'requires_note' => true, 'note_hint' => 'Pourquoi (congé, déplacement, réunion, inventaire) et jour de retour si connu']],
            ['d_closed', 'C\'est fermé à mon arrivée', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true, 'photo' => 'optional']],
            ['d_closed_works', 'Fermé pour travaux', 'delivery', 'unavailable', self::DIST, ['counts_as_attempt' => true, 'photo' => 'optional']],
            ['d_access_refused', 'L\'accès m\'est refusé à l\'entrée (sécurité, badge)', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true]],
            // Autre jour : report daté (à valider)
            ['d_not_today', 'Le destinataire réceptionne un autre jour', 'delivery', 'postponed', self::DIST, ['requires_date' => true]],
            ['d_postponed_recipient', 'Le destinataire demande de repasser un autre jour', 'delivery', 'postponed', self::DIST, ['requires_date' => true]],
            // Adresse, téléphone : le client corrige
            ['d_address_not_found', 'Je ne trouve pas l\'adresse', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_address']],
            ['d_relocated', 'L\'entreprise a déménagé', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_address']],
            ['d_inaccessible', 'Zone inaccessible', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true]],
            ['d_refuses_location', 'Le destinataire refuse de donner sa position', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true]],
            ['d_wrong_number', 'Le numéro du destinataire est faux', 'delivery', 'address', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_phone']],
            // Documents : le pli revient au client (compté dans son forfait), la course peut être reconduite
            ['d_no_purchase_order', 'Il manque le bon de commande', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_delivery_note', 'Il manque le bon de livraison', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_document_error', 'Il y a une erreur sur le document', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_incomplete', 'Le pli est incomplet (page ou pièce jointe manquante)', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true, 'note_hint' => 'Ce qui manque']],
            ['d_wrong_ncc', 'Le NCC est faux', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_vat', 'La TVA n\'apparaît pas', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_vat_not_due', 'La facture ne doit pas inclure la TVA', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_exemption', 'Il manque l\'attestation d\'exonération', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_wrong_amount', 'Le montant est faux', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_stamp', 'Il manque le cachet', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_no_signature', 'Il manque la signature', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_send_fne', 'Le destinataire veut la facture en FNE', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_send_email', 'Le destinataire veut la facture par e-mail', 'delivery', 'documents', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            // Destinataire
            ['d_wrong_name', 'Le nom du destinataire est faux', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_name']],
            ['d_renamed', 'L\'entreprise a changé de nom', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'destination_effect' => 'check_name']],
            ['d_contract_ended', 'Le contrat avec ce destinataire est résilié', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_company_gone', 'L\'entreprise n\'existe plus', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true, 'destination_effect' => 'inactive']],
            ['d_refused', 'Le destinataire refuse de réceptionner', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_already_received', 'Le destinataire a déjà reçu cette facture', 'delivery', 'recipient', self::DIST, ['counts_as_attempt' => true, 'triggers_return' => true]],
            ['d_other', 'Autre (préciser dans la note)', 'delivery', 'other', self::DIST, ['counts_as_attempt' => true, 'requires_note' => true]],
            // Consignes du destinataire (gardées sur l'adresse de l'annuaire pour les prochaines livraisons)
            ['d_receiving_hours', 'Le destinataire réceptionne seulement à certaines heures', 'delivery', 'info', self::DIST, ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Heures de réception (ex. de 8h à 12h)', 'visible_to_client' => true]],
            ['d_reception_desk', 'Le destinataire demande de déposer à l\'accueil ou au service courrier', 'delivery', 'info', self::DIST, ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Nom de la personne qui prend le pli', 'visible_to_client' => true]],
            ['d_other_department', 'À remettre à un autre service ou un autre bureau', 'delivery', 'info', self::DIST, ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'Le service ou le bureau', 'visible_to_client' => true]],
            // Ramassage chez le client (distribution, MAD)
            ['dp_client_unavailable', 'Le client est absent ou ne répond pas', 'pickup', 'pickup', self::DIST, []],
            ['dp_not_ready', 'Les plis ne sont pas prêts', 'pickup', 'pickup', self::DIST, []],
            ['dp_cancelled', 'Le client annule le ramassage', 'pickup', 'pickup', self::DIST, []],
            ['dp_slip_unsigned', 'Le bordereau n\'est pas signé', 'pickup', 'pickup', self::DIST, []],
            ['dp_no_address', 'L\'adresse du destinataire manque sur le pli', 'pickup', 'pickup', self::DIST, ['note_hint' => 'Le pli concerné']],
            // MAD : courses du client (banque, administration, attente)
            ['m_waiting', 'J\'attends chez le client (attente longue)', 'both', 'info', ['mad'], ['effect' => 'note', 'asks' => 'time', 'visible_to_client' => true]],
            ['m_extra_errand', 'Le client me demande une course en plus ou un détour', 'both', 'info', ['mad'], ['effect' => 'note', 'requires_note' => true, 'note_hint' => 'La course ou le détour demandé', 'visible_to_client' => true]],
            ['m_counter_closed', 'Le guichet est fermé (banque, administration)', 'delivery', 'unavailable', ['mad'], []],
            ['m_queue', 'Il y a trop d\'attente au guichet, je reviens un autre jour', 'delivery', 'postponed', ['mad'], ['requires_date' => true]],
            ['m_file_refused', 'Le dossier est refusé au guichet (pièce manquante)', 'delivery', 'documents', ['mad'], ['triggers_return' => true, 'requires_note' => true, 'note_hint' => 'La raison du refus']],

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
