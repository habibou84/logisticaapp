<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacités de chaque livreur (encaisser, MAD, intérieur, hors connexion, voir son gain), en plus des réglages de
 * l'entreprise : vide = comme l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->json('capabilities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn('capabilities'));
    }
};
