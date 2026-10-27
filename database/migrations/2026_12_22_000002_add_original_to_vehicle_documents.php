<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papiers des véhicules et permis : photo d'origine gardée à côté du document scanné, informations de la prise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->string('original_path')->nullable();
            $table->json('file_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_documents', fn (Blueprint $table) => $table->dropColumn(['original_path', 'file_meta']));
    }
};
