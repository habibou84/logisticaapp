<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Les marchands peuvent créer des courses en écrivant au numéro WhatsApp de l'entreprise
            $table->boolean('whatsapp_orders')->default(true)->after('sms_fallback');
        });

        // Messages reçus sur WhatsApp (journal, déduplication des webhooks)
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('from_phone', 20);
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // text | button | image | audio | location | other
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'from_phone', 'created_at']);
        });

        // Conversation en cours avec un expéditeur : brouillon de course, question en attente
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 20)->default('idle'); // idle | draft | confirm | track
            $table->json('draft')->nullable();
            $table->string('awaiting', 30)->nullable(); // champ demandé au marchand
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
        Schema::dropIfExists('inbound_messages');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('whatsapp_orders'));
    }
};
