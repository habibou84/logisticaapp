<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles sur mesure de l'équipe (écran Rôles) : nom affiché, modèle de départ, périmètre par défaut (activités,
 * zones, dépôts). Les rôles intégrés (administrateur, dispatcher…) restent définis dans le code.
 * Périmètre propre à un compte (zones, dépôts) : il l'emporte sur celui de son rôle, comme les activités.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_custom')->default(false);
            $table->string('label', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('template', 40)->nullable();
            $table->json('activities')->nullable();
            $table->json('zone_ids')->nullable();
            $table->json('hub_ids')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->json('zone_ids')->nullable();
            $table->json('hub_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['zone_ids', 'hub_ids']));
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['is_custom', 'label', 'description', 'template', 'activities', 'zone_ids', 'hub_ids']);
        });
    }
};
