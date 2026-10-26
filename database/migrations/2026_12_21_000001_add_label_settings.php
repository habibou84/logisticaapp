<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étiquettes : réglages (entreprise, client) et suivi de l'impression des courses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->json('label_settings')->nullable());
        // Réglage propre au client (vide : celui de l'entreprise)
        Schema::table('merchants', fn (Blueprint $table) => $table->json('label_settings')->nullable());
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('label_printed_at')->nullable();
            $table->unsignedSmallInteger('label_print_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['label_printed_at', 'label_print_count']));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('label_settings'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('label_settings'));
    }
};
