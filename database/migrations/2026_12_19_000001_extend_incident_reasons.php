<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motifs de non-livraison par activité et par famille (distribution, MAD), ce qu'ils demandent au livreur (note,
 * photo), leur effet sur l'annuaire ; notes de suivi de l'équipe sur une course (applies_to « follow_up ») ;
 * course reconduite à partir d'une autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reasons', function (Blueprint $table) {
            // pickup | delivery | both | follow_up (note de suivi de l'équipe)
            $table->string('family', 30)->nullable();
            // Activités où le motif est proposé (vide : toutes)
            $table->json('activities')->nullable();
            $table->boolean('requires_note')->default(false);
            // Photo demandée au livreur : off | optional | required
            $table->string('photo', 10)->default('off');
            // Annuaire : inactive | check_name | check_address | check_phone
            $table->string('destination_effect', 20)->nullable();
            // Note de suivi : la course attend (pas d'assignation automatique, hors des files « sans livreur »)
            $table->boolean('holds')->default(false);
            $table->boolean('visible_to_client')->default(false);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('follow_up_reason_id')->nullable()->constrained('incident_reasons')->nullOnDelete();
            $table->string('follow_up_note', 500)->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->foreignId('follow_up_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('renewed_from_id')->nullable()->constrained('orders')->nullOnDelete();
        });

        // Motifs de livraison existants : ceux de l'e-commerce (la distribution et la MAD ont les leurs)
        DB::table('incident_reasons')->whereNull('company_id')
            ->whereIn('code', ['unreachable', 'absent', 'wrong_address', 'postponed', 'no_money', 'refused', 'other'])
            ->update(['activities' => json_encode(['ecommerce'])]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_reason_id');
            $table->dropConstrainedForeignId('follow_up_by');
            $table->dropConstrainedForeignId('renewed_from_id');
            $table->dropColumn(['follow_up_note', 'follow_up_at']);
        });
        Schema::table('incident_reasons', fn (Blueprint $table) => $table->dropColumn(['family', 'activities', 'requires_note', 'photo', 'destination_effect', 'holds', 'visible_to_client']));
    }
};
