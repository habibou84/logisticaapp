<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournée du livreur : place de chaque livraison dans l'ordre choisi (calculé depuis sa position ou changé à la main).
 * Vide : pas encore placée (nouvelle mission), affichée après les autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('route_position')->nullable();
            $table->timestamp('route_planned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_assignments', fn (Blueprint $table) => $table->dropColumn(['route_position', 'route_planned_at']));
    }
};
