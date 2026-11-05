<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clôture de la caisse : une par jour et par entreprise. Elle fige ce qui est entré et sorti depuis la clôture
 * précédente, par moyen (espèces, Wave, Orange Money…), ce que la caisse a compté, l'écart et le fond laissé pour le
 * lendemain. Rappel le soir si la journée n'est pas clôturée, résumé aux administrateurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->timestamp('period_start');
            // Par moyen : début, entré, sorti, attendu, compté, écart
            $table->json('lines');
            $table->bigInteger('total_in')->default(0);
            $table->bigInteger('total_out')->default(0);
            $table->bigInteger('difference')->default(0);
            $table->string('explanation', 500)->nullable();
            $table->bigInteger('float_kept')->default(0);
            $table->bigInteger('deposited')->default(0);
            $table->bigInteger('with_couriers')->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['company_id', 'day']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->time('cash_close_reminder_at')->nullable()->default('20:00');
            $table->boolean('cash_close_summary')->default(true);
            $table->date('cash_close_reminded_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cash_close_reminder_at', 'cash_close_summary', 'cash_close_reminded_on']);
        });
        Schema::dropIfExists('cash_closings');
    }
};
