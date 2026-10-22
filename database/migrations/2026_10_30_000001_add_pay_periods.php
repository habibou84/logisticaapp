<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paie des livreurs, phase 2 : salaire de base et période de paie par plan, plafond
 * des retenues, fiches préparées automatiquement et paie gardée sur l'encaissé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_plans', function (Blueprint $table) {
            // Montant fixe par période (salarié ou mixte) ; 0 = pas de salaire
            $table->unsignedInteger('base_salary')->default(0)->after('max_amount');
            // weekly | biweekly | monthly ; null = paie préparée à la demande
            $table->string('pay_period', 12)->nullable()->after('base_salary');
            // Part maximale des gains d'une fiche que les retenues peuvent prendre (%)
            $table->unsignedTinyInteger('deduction_cap_percent')->nullable()->after('pay_period');
        });

        Schema::table('courier_payouts', function (Blueprint $table) {
            $table->boolean('automatic')->default(false)->after('status');
            // Payée en laissant le livreur garder le montant sur l'argent encaissé
            $table->boolean('compensated')->default(false)->after('method');
            $table->index(['courier_id', 'period_end']);
        });

        Schema::table('courier_earnings', function (Blueprint $table) {
            // Salaire de base : période concernée (un seul par livreur et par période)
            $table->date('period_start')->nullable()->after('detail');
            $table->date('period_end')->nullable()->after('period_start');
        });

        Schema::table('courier_advances', function (Blueprint $table) {
            // Paie gardée sur l'encaissé : montant négatif lié à la fiche de paie
            $table->foreignId('courier_payout_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courier_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_payout_id');
        });
        Schema::table('courier_earnings', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end']);
        });
        Schema::table('courier_payouts', function (Blueprint $table) {
            $table->dropIndex(['courier_id', 'period_end']);
            $table->dropColumn(['automatic', 'compensated']);
        });
        Schema::table('pay_plans', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'pay_period', 'deduction_cap_percent']);
        });
    }
};
