<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bouton « Connecter WhatsApp » (inscription intégrée de Meta) : manière dont le numéro a été
 * branché et code PIN de l'activation du numéro (vérification en deux étapes), chiffré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('connected_via', 20)->nullable()->after('connected_at');
            $table->text('registration_pin')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['connected_via', 'registration_pin']);
        });
    }
};
