<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Jeton remis par le bouton « Connecter WhatsApp » : date d'expiration (60 jours selon la
 * configuration Meta), alerte envoyée avant l'échéance, et bascule sur le jeton permanent
 * de la plateforme (utilisateur système de Jibiat ajouté au compte WhatsApp de l'entreprise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->timestamp('token_expires_at')->nullable()->after('registration_pin');
            $table->timestamp('token_warned_at')->nullable()->after('token_expires_at');
            $table->boolean('platform_token')->default(false)->after('token_warned_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['token_expires_at', 'token_warned_at', 'platform_token']);
        });
    }
};
