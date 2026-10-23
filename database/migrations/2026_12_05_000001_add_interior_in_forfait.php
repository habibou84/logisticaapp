<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrat de distribution : les courses vers l'intérieur comptent dans les courses incluses du forfait
 * (au-delà, chacune au tarif de sa ville). Décoché : l'intérieur est toujours facturé à part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->boolean('interior_in_forfait')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn('interior_in_forfait'));
    }
};
