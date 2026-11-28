<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Événements des courses, liste complète :
 * - heure précise de livraison demandée par le client (orders.delivery_time), proposée avec un report ;
 * - ce que le livreur saisit avec un événement (asks : heure de livraison, heure) et l'aide à la saisie (note_hint) ;
 * - libellés simples pour les motifs communs : seulement ceux que l'administrateur n'a pas renommés.
 * Compatible avec la version précédente : colonnes ajoutées, nullables.
 */
return new class extends Migration
{
    /** code => [ancien libellé, nouveau libellé, autres réglages] */
    private const RENAMED = [
        'i_time_slot' => ['Le client demande un créneau', 'Le client veut être livré à une heure précise', ['asks' => 'delivery_time', 'requires_note' => false]],
        'i_call_back' => ['Le client demande d\'être rappelé', 'Le client demande qu\'on le rappelle', ['asks' => 'time']],
        'i_waiting' => ['Client en route : j\'attends sur place', 'Le client est en route, j\'attends sur place', []],
        'i_third_party' => ['Remise à un tiers (gardien, voisin, collègue)', 'Le client veut que le colis soit remis à quelqu\'un d\'autre (gardien, voisin, collègue)', ['note_hint' => 'Nom de la personne']],
        'i_meeting_point' => ['Lieu de rencontre changé', 'Le client donne un autre lieu de rencontre, tout près', ['note_hint' => 'Le lieu de rencontre']],
        'i_landmark' => ['Repère, indications d\'accès', 'Le client donne un repère pour trouver l\'adresse', ['note_hint' => 'Le repère']],
        'postponed' => ['Livraison reportée à la demande du client', 'Le client demande à être livré un autre jour', []],
        'unreachable' => ['Destinataire injoignable', 'Le client ne répond pas au téléphone', []],
        'absent' => ['Destinataire absent', 'Le client est absent à l\'adresse', []],
        'wrong_address' => ['Adresse introuvable ou erronée', 'Je ne trouve pas l\'adresse', ['destination_effect' => 'check_address']],
        'no_money' => ['Le client n\'a pas l\'argent', 'Le client n\'a pas l\'argent', []],
        'refused' => ['Colis refusé par le client', 'Le client refuse le colis', []],
        'not_compliant' => ['Produit non conforme à la commande', 'Le produit ne correspond pas à la commande', []],
        'price_disputed' => ['Le client conteste le prix', 'Le client conteste le prix', ['note_hint' => 'Prix annoncé par le client']],
        'unsafe' => ['Situation dangereuse, client agressif', 'Client agressif, situation dangereuse', []],
        'damaged' => ['Colis endommagé', 'Le colis est abîmé', ['photo' => 'required', 'triggers_return' => true, 'needs_validation' => true, 'requires_note' => true, 'note_hint' => 'Abîmé chez le marchand ou pendant le transport ?']],
        'merchant_unavailable' => ['Marchand absent ou injoignable', 'Le marchand est absent ou ne répond pas', []],
        'package_not_ready' => ['Colis pas prêt', 'Le colis n\'est pas prêt', []],
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Heure précise demandée par le client (« 17:30 »), en plus du créneau large
            $table->string('delivery_time', 5)->nullable();
        });
        Schema::table('incident_reasons', function (Blueprint $table) {
            // Ce que le livreur saisit : delivery_time (heure de livraison, affichée partout) ou time (une heure)
            $table->string('asks', 20)->nullable();
            // Aide à la saisie de la note (« Nom de la personne »)
            $table->string('note_hint', 120)->nullable();
        });
        Schema::table('order_proposals', function (Blueprint $table) {
            $table->string('proposed_time', 5)->nullable();
        });

        foreach (self::RENAMED as $code => [$old, $new, $options]) {
            DB::table('incident_reasons')->whereNull('company_id')->where('code', $code)->where('label', $old)
                ->update(['label' => $new, ...$options]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMED as $code => [$old, $new]) {
            DB::table('incident_reasons')->whereNull('company_id')->where('code', $code)->where('label', $new)->update(['label' => $old]);
        }
        Schema::table('order_proposals', fn (Blueprint $table) => $table->dropColumn('proposed_time'));
        Schema::table('incident_reasons', fn (Blueprint $table) => $table->dropColumn(['asks', 'note_hint']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_time'));
    }
};
