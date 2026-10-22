<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Page de commande du marchand (/b/{shop_slug}) : catalogue, commande en paiement à la livraison
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('shop_slug', 60)->nullable()->unique()->after('source');
            $table->boolean('shop_enabled')->default(false)->after('shop_slug');
            $table->string('shop_intro', 300)->nullable()->after('shop_enabled');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('description');
            $table->boolean('shop_visible')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['photo_path', 'shop_visible']));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn(['shop_slug', 'shop_enabled', 'shop_intro']));
    }
};
