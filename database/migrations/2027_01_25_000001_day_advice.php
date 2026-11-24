<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conseil d'ouverture ou de fermeture de la journée (Poste de pilotage) : ses critères, l'heure limite repoussée
 * pour aujourd'hui seulement quand on rouvre, et les conseils écartés pour la journée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('day_advice')->nullable();
            $table->date('cutoff_extended_on')->nullable();
            $table->string('cutoff_extended_to', 5)->nullable();
            $table->json('day_advice_dismissed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['day_advice', 'cutoff_extended_on', 'cutoff_extended_to', 'day_advice_dismissed']));
    }
};
