<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution : nombre de plis ou de chèques d'une course (une même remise), et nombre d'envois facturés
 * (le nombre de plis, ou 1 si l'administrateur facture à la course : Réglages › Courses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('shipment_quantity')->default(1);
            $table->unsignedInteger('billing_units')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['shipment_quantity', 'billing_units']));
    }
};
