<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            // Zone d'expédition : le livreur dépose le colis à une gare ou chez un transporteur
            $table->boolean('is_shipping')->default(false)->after('city');
            // Frais d'expédition habituels, à titre indicatif (le montant réel est saisi par le livreur)
            $table->unsignedInteger('shipping_fee_estimate')->nullable()->after('is_shipping');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_shipping')->default(false)->after('delivery_time_slot');
            // Renseignés au dépôt à la gare
            $table->unsignedInteger('shipping_fee')->nullable()->after('is_shipping');
            $table->string('shipping_carrier', 100)->nullable()->after('shipping_fee');
            $table->string('shipping_reference', 100)->nullable()->after('shipping_carrier');
        });

        Schema::table('cash_collections', function (Blueprint $table) {
            // Somme avancée par le livreur pour la course (frais d'expédition) : déduite de son versement
            $table->unsignedInteger('courier_expense')->default(0)->after('amount_collected');
        });

        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->integer('total_shipping_fees')->default(0)->after('total_fees');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_payouts', fn (Blueprint $table) => $table->dropColumn('total_shipping_fees'));
        Schema::table('cash_collections', fn (Blueprint $table) => $table->dropColumn('courier_expense'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['is_shipping', 'shipping_fee', 'shipping_carrier', 'shipping_reference']));
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn(['is_shipping', 'shipping_fee_estimate']));
    }
};
