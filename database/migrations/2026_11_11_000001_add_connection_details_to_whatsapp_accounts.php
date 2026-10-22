<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Connexion du numéro WhatsApp vérifiée auprès de Meta : nom affiché, note de qualité,
 * abonnement aux messages reçus, dernière erreur. Le mode réel ou simulation se décide
 * désormais entreprise par entreprise (numéro connecté = envois réels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('verified_name')->nullable()->after('display_phone');
            $table->string('quality_rating', 20)->nullable()->after('verified_name');
            $table->string('last_error', 500)->nullable()->after('status');
            $table->timestamp('connected_at')->nullable()->after('last_error');
            $table->timestamp('webhook_subscribed_at')->nullable()->after('connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['verified_name', 'quality_rating', 'last_error', 'connected_at', 'webhook_subscribed_at']);
        });
    }
};
