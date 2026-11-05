<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caisse : journal de tout ce qui touche l'argent (qui, quand, combien), annulation le jour même d'un versement ou
 * d'une avance, et alerte quand un livreur garde l'argent trop longtemps ou au-delà d'un plafond.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount')->nullable();
            $table->string('reference', 40)->nullable();
            $table->string('description', 500);
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'created_at']);
        });

        foreach (['courier_remittances', 'courier_advances'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('cancel_reason')->nullable();
            });
        }

        Schema::table('companies', function (Blueprint $table) {
            // Argent gardé par un livreur : plus de N heures, ou plus que ce montant (vide : pas de plafond)
            $table->unsignedSmallInteger('cash_alert_hours')->default(24);
            $table->unsignedInteger('cash_alert_amount')->nullable();
            $table->boolean('cash_alert_courier')->default(true);
        });

        Schema::table('couriers', function (Blueprint $table) {
            $table->timestamp('cash_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn('cash_alerted_at'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['cash_alert_hours', 'cash_alert_amount', 'cash_alert_courier']));
        foreach (['courier_remittances', 'courier_advances'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('cancelled_by');
                $table->dropColumn(['cancelled_at', 'cancel_reason']);
            });
        }
        Schema::dropIfExists('cash_logs');
    }
};
