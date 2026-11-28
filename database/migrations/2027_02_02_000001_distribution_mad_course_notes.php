<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notes des courses de distribution et de MAD :
 * - libellés en phrases simples (seulement ceux que l'administrateur n'a pas modifiés) ;
 * - « en congé, en déplacement, en réunion, en inventaire » réunis en une seule note (les anciens désactivés) ;
 * - « Ne reçoit pas ce jour » devient un report daté (à valider), « Reporté par le client » quitte la liste du livreur ;
 * - consignes du destinataire et ramassage façon e-commerce réservés à l'e-commerce (la distribution et la MAD ont
 *   les leurs, ajoutées par le seeder) ;
 * - consignes permanentes d'une adresse de l'annuaire (heures de réception, dépôt à l'accueil, autre service).
 * Compatible avec la version précédente : colonne ajoutée, motifs désactivés plutôt que supprimés.
 */
return new class extends Migration
{
    /** code => [ancien libellé, nouveau libellé] */
    private const RENAMED = [
        'd_unreachable' => ['Injoignable', 'Le destinataire ne répond pas au téléphone'],
        'd_absent' => ['Destinataire absent', 'Le destinataire est absent'],
        'd_closed' => ['Fermé à l\'arrivée', 'C\'est fermé à mon arrivée'],
        'd_closed_works' => ['Fermé pour travaux', 'Fermé pour travaux'],
        'd_not_today' => ['Ne reçoit pas ce jour', 'Le destinataire réceptionne un autre jour'],
        'd_postponed_recipient' => ['Reporté par le destinataire', 'Le destinataire demande de repasser un autre jour'],
        'd_address_not_found' => ['Adresse non retrouvée', 'Je ne trouve pas l\'adresse'],
        'd_relocated' => ['Délocalisée', 'L\'entreprise a déménagé'],
        'd_inaccessible' => ['Zone inaccessible', 'Zone inaccessible'],
        'd_refuses_location' => ['Refus de se localiser', 'Le destinataire refuse de donner sa position'],
        'd_wrong_number' => ['Numéro incorrect', 'Le numéro du destinataire est faux'],
        'd_no_purchase_order' => ['Absence de bon de commande', 'Il manque le bon de commande'],
        'd_no_delivery_note' => ['Absence de bon de livraison', 'Il manque le bon de livraison'],
        'd_document_error' => ['Erreur sur le document', 'Il y a une erreur sur le document'],
        'd_wrong_ncc' => ['NCC incorrecte', 'Le NCC est faux'],
        'd_no_vat' => ['Absence de la TVA', 'La TVA n\'apparaît pas'],
        'd_vat_not_due' => ['Ne doit pas inclure la TVA', 'La facture ne doit pas inclure la TVA'],
        'd_no_exemption' => ['Absence d\'attestation d\'exonération', 'Il manque l\'attestation d\'exonération'],
        'd_wrong_amount' => ['Montant incorrect', 'Le montant est faux'],
        'd_no_stamp' => ['Absence de cachet', 'Il manque le cachet'],
        'd_no_signature' => ['Absence de signature', 'Il manque la signature'],
        'd_send_fne' => ['Transmettre en FNE', 'Le destinataire veut la facture en FNE'],
        'd_send_email' => ['Transmettre par email', 'Le destinataire veut la facture par e-mail'],
        'd_wrong_name' => ['Erreur sur le nom du destinataire', 'Le nom du destinataire est faux'],
        'd_renamed' => ['Changement de dénomination', 'L\'entreprise a changé de nom'],
        'd_contract_ended' => ['Contrat résilié', 'Le contrat avec ce destinataire est résilié'],
        'd_company_gone' => ['Entreprise n\'existe plus', 'L\'entreprise n\'existe plus'],
        'd_refused' => ['Refus de réceptionner', 'Le destinataire refuse de réceptionner'],
        'd_already_received' => ['Facture déjà réceptionnée', 'Le destinataire a déjà reçu cette facture'],
        'd_other' => ['Autres', 'Autre (préciser dans la note)'],
    ];

    /** Remplacés par « La personne qui réceptionne n'est pas disponible », ou retirés de la liste du livreur */
    private const DEACTIVATED = ['d_annual_leave', 'd_travelling', 'd_inventory', 'd_meeting', 'd_postponed_client'];

    /** Consignes du destinataire et ramassage écrits pour l'e-commerce */
    private const ECOMMERCE_ONLY = [
        'i_time_slot', 'i_call_back', 'i_waiting', 'i_third_party', 'i_meeting_point', 'i_landmark',
        'merchant_unavailable', 'package_not_ready', 'p_packaging', 'p_cancelled', 'p_mismatch',
    ];

    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            // Consignes permanentes de l'adresse : heures de réception, dépôt à l'accueil, service à qui remettre
            $table->text('instructions')->nullable();
        });

        $common = fn () => DB::table('incident_reasons')->whereNull('company_id');
        foreach (self::RENAMED as $code => [$old, $new]) {
            $common()->where('code', $code)->where('label', $old)->update(['label' => $new]);
        }
        // Un autre jour : report daté (à valider), plus une tentative
        $common()->where('code', 'd_not_today')->where('label', self::RENAMED['d_not_today'][1])
            ->update(['requires_date' => true, 'counts_as_attempt' => false, 'needs_validation' => true, 'family' => 'postponed']);
        $common()->whereIn('code', self::DEACTIVATED)->update(['is_active' => false]);
        $common()->whereIn('code', self::ECOMMERCE_ONLY)->whereNull('activities')->update(['activities' => json_encode(['ecommerce'])]);
    }

    public function down(): void
    {
        $common = fn () => DB::table('incident_reasons')->whereNull('company_id');
        $common()->whereIn('code', self::ECOMMERCE_ONLY)->update(['activities' => null]);
        $common()->whereIn('code', self::DEACTIVATED)->update(['is_active' => true]);
        foreach (self::RENAMED as $code => [$old, $new]) {
            $common()->where('code', $code)->where('label', $new)->update(['label' => $old]);
        }
        Schema::table('destinations', fn (Blueprint $table) => $table->dropColumn('instructions'));
    }
};
