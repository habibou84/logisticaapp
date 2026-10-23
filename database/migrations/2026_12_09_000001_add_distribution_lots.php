<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution ponctuelle : contrat d'un lot de colis (« 50 courriers à distribuer »). Prix global du lot, ou
 * chaque colis au prix de la grille ; au-delà du nombre prévu, au prix unitaire. Plusieurs lots en même temps,
 * à côté d'un contrat mensuel ; repris sur la facture du mois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->string('lot_label', 100)->nullable();
            $table->unsignedInteger('lot_quantity')->nullable();
            // Prix global du lot (vide : chaque colis au prix de la grille)
            $table->unsignedInteger('lot_price')->nullable();
            // Date limite de distribution souhaitée
            $table->date('lot_due_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn(['lot_label', 'lot_quantity', 'lot_price', 'lot_due_on']));
    }
};
