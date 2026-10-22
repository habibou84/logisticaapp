<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moyens de paiement des factures clients, choisis par l'entreprise : comptes bancaires (IBAN),
 * comptes Mobile Money, chèque, espèces, autre. Le texte libre reste en complément.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('invoice_payment_methods')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('invoice_payment_methods'));
    }
};
