<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Alerte « livreur libre avec des ramassages près de lui » : l'entreprise peut la couper.
 * - Un même téléphone peut recevoir les notifications de plusieurs comptes (e-commerçant et livreur, par exemple).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('courier_available_alert')->default(true);
        });

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint_hash']);
            $table->unique(['endpoint_hash', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint_hash', 'user_id']);
            $table->unique('endpoint_hash');
        });

        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('courier_available_alert'));
    }
};
