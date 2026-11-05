<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envoi des courses de distribution et de MAD : réglages de l'entreprise (types de pli, décompte du forfait
 * montré au client) et, sur la course, ce qu'est le pli (facture, courrier, carte d'invitation…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->json('shipment_settings')->nullable());
        Schema::table('orders', fn (Blueprint $table) => $table->string('pli_kind', 60)->nullable()->after('shipment_quantity'));
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('shipment_settings'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('pli_kind'));
    }
};
