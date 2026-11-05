<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages aux e-commerçants réglés par l'agence : WhatsApp (ou notification sur le téléphone) oui ou non par étape,
 * et l'e-commerçant peut-il changer ces choix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('merchant_whatsapp_events')->nullable();
            $table->boolean('merchant_messages_locked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['merchant_whatsapp_events', 'merchant_messages_locked']));
    }
};
