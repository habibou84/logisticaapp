<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course d'essai de la mise en route : marquée, puis effacée avec tout ce qu'elle a produit ; l'entreprise garde la
 * trace qu'une course d'essai a été livrée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_test')->default(false);
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('is_test')->default(false);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('setup_test_delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('setup_test_delivered_at'));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('is_test'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('is_test'));
    }
};
