<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clé de reprise : l'ancienne application (Laravel 5.8) envoie ses comptes avec cette clé,
 * créée par l'administrateur dans Paramètres et valable quelques jours. Seule son empreinte est gardée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('legacy_import_key_hash', 64)->nullable()->unique();
            $table->timestamp('legacy_import_key_expires_at')->nullable();
            $table->timestamp('legacy_import_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['legacy_import_key_hash']);
            $table->dropColumn(['legacy_import_key_hash', 'legacy_import_key_expires_at', 'legacy_import_used_at']);
        });
    }
};
