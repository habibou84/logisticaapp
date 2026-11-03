<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiques de rentabilité : frais fixes saisis par l'entreprise (loyer, salaires de l'équipe, téléphone…),
 * réglages des statistiques (objectifs du mois, seuils de rentabilité et des forfaits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            // rent | staff | telecom | insurance | marketing | taxes | other
            $table->string('category', 20)->default('other');
            $table->unsignedBigInteger('amount');
            // monthly : chaque mois de starts_on à ends_on ; once : une fois, le jour starts_on
            $table->string('frequency', 10)->default('monthly');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_on']);
        });

        Schema::table('companies', fn (Blueprint $table) => $table->json('stats_settings')->nullable());
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_costs');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('stats_settings'));
    }
};
