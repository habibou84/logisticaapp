<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zones et tarifs : l'entreprise ne collecte pas forcément partout où elle livre (zones.is_pickup, partout par
 * défaut) ; prix de base de la grille (tout trajet sans prix) ; paliers de prix par commune de départ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->boolean('is_pickup')->default(true)->after('is_active');
        });
        Schema::table('pricing_grids', function (Blueprint $table) {
            $table->unsignedInteger('base_price')->nullable();
            $table->json('tiers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pricing_grids', fn (Blueprint $table) => $table->dropColumn(['base_price', 'tiers']));
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('is_pickup'));
    }
};
