<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qui paie la livraison des commandes de la boutique en ligne (indépendant du réglage des autres courses)
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('shop_fee_payer', 20)->default('recipient')->after('shop_intro');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('shop_fee_payer'));
    }
};
