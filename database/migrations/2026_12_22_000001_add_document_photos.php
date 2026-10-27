<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos de documents : réglages du scanner, photo d'origine gardée à côté de la version traitée,
 * informations de la prise (rendu, qualité, heure, position) et envoi hors connexion sans doublon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->json('photo_settings')->nullable());
        Schema::table('order_attachments', function (Blueprint $table) {
            $table->string('original_path')->nullable();
            $table->json('meta')->nullable();
            // Identifiant donné par le téléphone : une photo renvoyée après une coupure n'est gardée qu'une fois
            $table->string('client_ref', 64)->nullable();
            $table->unique(['order_id', 'client_ref']);
        });
        Schema::table('return_slips', function (Blueprint $table) {
            $table->string('photo_original_path')->nullable();
            $table->json('photo_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('return_slips', fn (Blueprint $table) => $table->dropColumn(['photo_original_path', 'photo_meta']));
        Schema::table('order_attachments', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'client_ref']);
            $table->dropColumn(['original_path', 'meta', 'client_ref']);
        });
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('photo_settings'));
    }
};
