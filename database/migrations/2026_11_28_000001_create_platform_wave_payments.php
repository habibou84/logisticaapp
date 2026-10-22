<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements des factures de la plateforme par le lien marchand Wave (sans API) : annoncés par l'entreprise,
 * vérifiés puis validés par le super administrateur (console, ou lien reçu par e-mail ou SMS avec son code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_wave_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('payer_phone', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index(); // pending | received | missing
            $table->timestamp('claimed_at')->nullable();
            $table->json('alerts')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_wave_payments');
    }
};
