<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Remise des colis retournés au client, confirmée par le client : il scanne le QR code du livreur et envoie le
 * message WhatsApp préparé (ou écrit « retour », ou confirme dans son application). Sans confirmation possible, le
 * livreur fait signer ou photographie la remise et l'agence vérifie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('return_confirmation')->default(false);
        });

        Schema::create('return_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            // Référence courte citée dans le message WhatsApp (R-4821)
            $table->string('reference', 12);
            // pending : en attente du client ; confirmed ; unconfirmed : remise de secours, à vérifier ;
            // disputed : « il en manque » ; resolved : tranché par l'agence ; cancelled
            $table->string('status', 20)->default('pending');
            $table->json('order_ids');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            // whatsapp (message préparé), whatsapp_word (« retour » + bouton), app
            $table->string('confirmed_via', 20)->nullable();
            $table->string('confirmed_phone', 32)->nullable();
            $table->string('fallback_reason', 30)->nullable();
            $table->string('received_by_name')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('handed_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 20)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'reference']);
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_handovers');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('return_confirmation'));
    }
};
