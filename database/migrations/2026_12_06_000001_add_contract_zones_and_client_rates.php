<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrats : chaque contrat choisit ses zones couvertes par le forfait (communes d'Abidjan, villes de
 * l'intérieur…). Tarifs : grille commune, et prix négociés par client qui la remplacent (merchant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plan_zone', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->unique(['billing_plan_id', 'zone_id']);
        });

        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn('interior_in_forfait'));

        Schema::table('interior_rates', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'city', 'delivery_delay']);
            // Vide : grille commune ; sinon prix négociés de ce client
            $table->foreignId('merchant_id')->nullable()->after('company_id')->constrained()->cascadeOnDelete();
            $table->index(['company_id', 'merchant_id', 'city']);
        });

        Schema::table('distribution_rates', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'delivery_delay', 'shipment_type']);
            $table->foreignId('merchant_id')->nullable()->after('company_id')->constrained()->cascadeOnDelete();
            $table->index(['company_id', 'merchant_id', 'delivery_delay']);
        });
    }

    public function down(): void
    {
        Schema::table('distribution_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_id');
            $table->unique(['company_id', 'delivery_delay', 'shipment_type']);
        });
        Schema::table('interior_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_id');
            $table->unique(['company_id', 'city', 'delivery_delay']);
        });
        Schema::table('billing_plans', fn (Blueprint $table) => $table->boolean('interior_in_forfait')->default(true));
        Schema::dropIfExists('billing_plan_zone');
    }
};
