<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heure limite du jour : passé cette heure (fuseau de l'entreprise), les courses du jour
 * encore sans livreur risquent de ne pas être livrées aujourd'hui ; le dispatch est alerté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // HH:MM ; null = pas d'heure limite
            $table->string('daily_cutoff_time', 5)->nullable()->after('delivery_assign_alert_minutes');
            // Dernier jour (local) où l'alerte de l'heure limite a été envoyée
            $table->date('cutoff_alerted_on')->nullable()->after('daily_cutoff_time');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['daily_cutoff_time', 'cutoff_alerted_on']));
    }
};
