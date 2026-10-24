<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution et MAD au choix de l'administrateur : preuve de remise, messages aux destinataires,
 * réservation des livreurs en MAD (réglages de l'entreprise, que chaque contrat peut changer) ;
 * nom de la personne qui a reçu la course ; paie des livreurs à la journée de MAD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('activity_options')->nullable();
        });
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->json('options')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('received_by_name', 120)->nullable();
        });
        Schema::table('pay_plans', function (Blueprint $table) {
            $table->unsignedInteger('mad_daily_rate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pay_plans', fn (Blueprint $table) => $table->dropColumn('mad_daily_rate'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('received_by_name'));
        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn('options'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('activity_options'));
    }
};
