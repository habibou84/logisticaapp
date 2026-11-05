<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comment le marchand veut être payé : Wave, Orange Money, MTN, Moov ou banque, avec lien et QR code
        Schema::create('merchant_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                        // wave | orange_money | mtn_momo | moov_money | bank
            $table->string('number', 60);                      // numéro mobile money ou RIB
            $table->string('holder_name', 120)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('payment_link', 500)->nullable();
            $table->string('qr_path')->nullable();
            $table->string('qr_mime', 100)->nullable();
            $table->boolean('is_preferred')->default(false);
            // Tout ajout ou changement du marchand est vérifié par l'agence avant de servir
            $table->string('status', 20)->default('pending');  // pending | verified | rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejected_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });

        // Qui a ajouté, changé, validé ou retiré quel moyen, et quand
        Schema::create('merchant_payment_method_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('merchant_payment_methods')->nullOnDelete();
            $table->string('action', 20);                      // added | updated | removed | verified | rejected | preferred
            $table->string('summary', 200);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('by_merchant')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payment_method_events');
        Schema::dropIfExists('merchant_payment_methods');
    }
};
