<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Position de ramassage des marchands : d'où elle vient (le marchand sur place, l'agence,
 * ou les ramassages des livreurs), quand, et avec quelle précision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // merchant | staff | courier
            $table->string('pickup_location_source', 10)->nullable()->after('pickup_lng');
            $table->timestamp('pickup_located_at')->nullable()->after('pickup_location_source');
            // Précision en mètres (GPS du téléphone, ou dispersion des ramassages)
            $table->unsignedInteger('pickup_location_accuracy')->nullable()->after('pickup_located_at');
        });

        // Positions déjà saisies : par l'agence
        DB::table('merchants')->whereNotNull('pickup_lat')->update(['pickup_location_source' => 'staff', 'pickup_located_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn(['pickup_location_source', 'pickup_located_at', 'pickup_location_accuracy']));
    }
};
