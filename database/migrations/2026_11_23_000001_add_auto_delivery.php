<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livraison du jour même automatique : mode (désactivée, à l'essai, active), plafond d'encaissement, même livreur
 * du ramassage à la livraison, heure limite par jour de la semaine ; zones principales et de secours, capacité
 * (colis, argent en main) et fin de service des livreurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('auto_delivery', 10)->default('off');
            $table->unsignedInteger('auto_delivery_max_cod')->nullable();
            $table->boolean('same_courier_delivery')->default(false);
            $table->json('cutoff_by_day')->nullable();
        });

        Schema::table('couriers', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_parcels')->nullable();
            $table->unsignedInteger('max_cash')->nullable();
            $table->string('shift_ends_at', 5)->nullable();
        });

        Schema::table('courier_zone', function (Blueprint $table) {
            $table->boolean('is_backup')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('courier_zone', fn (Blueprint $table) => $table->dropColumn('is_backup'));
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn(['max_parcels', 'max_cash', 'shift_ends_at']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['auto_delivery', 'auto_delivery_max_cod', 'same_courier_delivery', 'cutoff_by_day']));
    }
};
