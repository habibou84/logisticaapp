<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Après une mission ramassage : le livreur garde les colis jusqu'à leur livraison, les rapporte
     * au dépôt, ou le dispatch choisit à chaque mission. Le choix retenu est noté sur la course.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('pickup_mission_custody', 10)->default('choose')->after('parcel_hold_alert_hours');
            // Heure locale de fermeture du dépôt (HH:MM) : passé cette heure, le livreur garde les colis
            $table->string('depot_closes_at', 5)->nullable()->after('pickup_mission_custody');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('custody_plan', 10)->nullable()->after('held_since');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['pickup_mission_custody', 'depot_closes_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('custody_plan');
        });
    }
};
