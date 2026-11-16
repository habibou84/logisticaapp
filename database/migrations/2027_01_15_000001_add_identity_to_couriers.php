<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identité du livreur : sa photo, la photo de sa pièce d'identité et le numéro de la pièce
 * (fichiers sur le disque privé, servis par l'API à l'équipe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('vehicle_plate');
            $table->string('id_document_path')->nullable()->after('photo_path');
            $table->string('id_number', 60)->nullable()->after('id_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn(['photo_path', 'id_document_path', 'id_number']));
    }
};
