<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace client (distribution, MAD) : moyens de paiement imprimés sur les factures, rappels de facture
 * et alertes de forfait déjà envoyés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Virement (IBAN), Mobile Money, ordre des chèques… : texte libre sur les factures
            $table->text('invoice_payment_info')->nullable();
        });

        Schema::table('client_invoices', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
        });

        Schema::table('billing_plans', function (Blueprint $table) {
            // Dernière alerte de consommation du forfait : « AAAA-MM:80 » ou « AAAA-MM:100 »
            $table->string('usage_alert', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn('usage_alert'));
        Schema::table('client_invoices', fn (Blueprint $table) => $table->dropColumn(['reminded_at', 'overdue_notified_at']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('invoice_payment_info'));
    }
};
