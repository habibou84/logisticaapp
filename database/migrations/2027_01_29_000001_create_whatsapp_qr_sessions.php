<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp des alertes, relié par QR code : état de la session et message passé par ce moyen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_qr_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // disconnected, scanning, connected
            $table->string('status', 20)->default('disconnected');
            $table->string('phone', 32)->nullable();
            $table->string('name')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('warned_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('outbound_messages', function (Blueprint $table) {
            // « qr » : envoyé par le WhatsApp des alertes (QR code) plutôt que par l'API de Meta
            $table->string('via', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropColumn('via');
        });
        Schema::dropIfExists('whatsapp_qr_sessions');
    }
};
