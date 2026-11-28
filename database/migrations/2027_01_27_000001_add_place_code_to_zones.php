<?php

use App\Services\Zones\StandardZones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zones standard (repris de Delivery App) : chaque zone porte le code de son lieu dans la liste des lieux (communes et
 * quartiers d'Abidjan, villes de l'intérieur), null pour une zone propre à l'entreprise (zone d'expédition, lieu
 * ajouté à la main). Les zones existantes sont rattachées à leur lieu, les lieux manquants ajoutés.
 * Colonne ajoutée seulement : la version précédente l'ignore (retour arrière sans toucher à la base).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->string('place_code', 120)->nullable()->after('parent_id');
            $table->index(['company_id', 'place_code']);
        });

        app(StandardZones::class)->syncAll();
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'place_code']);
            $table->dropColumn('place_code');
        });
    }
};
