<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intégrations des statistiques : lien Google Sheets (clé secrète), rapports mensuels des clients sous contrat
 * (préparés le 1er du mois, validés par l'équipe avant l'envoi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->string('stats_feed_token', 64)->nullable()->unique());

        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            // draft : à valider par l'équipe ; sent : envoyé, visible dans l'espace client
            $table->string('status', 10)->default('draft');
            $table->json('data');
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'period']);
            $table->index(['company_id', 'period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reports');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('stats_feed_token'));
    }
};
