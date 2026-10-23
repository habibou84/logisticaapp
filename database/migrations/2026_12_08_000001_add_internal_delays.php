<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Délai interne de distribution : l'entreprise se fixe un délai (par activité, ou par contrat) à partir de la
 * création de la course ; alerte « risque de retard » à 80 %, « en retard » à 100 %. Pour l'équipe seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // {"ecommerce": null, "distribution": 48, "mad": 24} : heures, vide = pas de délai interne
            $table->json('internal_delays')->nullable();
        });

        Schema::table('billing_plans', function (Blueprint $table) {
            // Délai propre au contrat (heures), sinon celui de l'activité
            $table->unsignedSmallInteger('internal_delay_hours')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('internal_due_at')->nullable()->index();
            // Alerte déjà envoyée : 1 risque de retard, 2 en retard
            $table->unsignedTinyInteger('internal_alert')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['internal_due_at', 'internal_alert']));
        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn('internal_delay_hours'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('internal_delays'));
    }
};
