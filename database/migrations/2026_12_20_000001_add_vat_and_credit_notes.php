<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TVA des factures des clients, identité fiscale (NCC) de l'entreprise et des clients, avoirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Identité fiscale imprimée sur les factures
            $table->string('legal_name')->nullable();
            $table->string('ncc', 30)->nullable();
            $table->string('rccm', 60)->nullable();
            $table->string('tax_regime', 60)->nullable();
            $table->string('tax_office', 120)->nullable();
            // TVA : activée, taux, prix saisis HT ou TTC, mention d'exonération
            $table->json('vat_settings')->nullable();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->string('ncc', 30)->nullable();
            $table->string('rccm', 60)->nullable();
            $table->string('billing_address', 500)->nullable();
            // Exonéré de TVA : référence de l'attestation, fin de validité
            $table->boolean('vat_exempt')->default(false);
            $table->string('vat_exemption_ref', 120)->nullable();
            $table->date('vat_exemption_until')->nullable();
        });

        Schema::table('client_invoices', function (Blueprint $table) {
            // Figés à l'émission : taux, base des prix, montants ; total = TTC (à payer)
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->string('vat_basis', 3)->default('ht');
            $table->unsignedInteger('total_ht')->default(0);
            $table->unsignedInteger('vat_amount')->default(0);
            // Mention d'exonération du client (attestation)
            $table->string('vat_exemption', 255)->nullable();
            // Total des avoirs émis sur la facture (TTC)
            $table->unsignedInteger('credited_amount')->default(0);
            // Identité fiscale du client au moment de l'émission
            $table->json('client_snapshot')->nullable();
        });
        DB::table('client_invoices')->update(['total_ht' => DB::raw('total')]);

        Schema::create('client_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 20);
            // AV-AAAAMM-0001
            $table->string('number', 30)->unique();
            $table->date('issued_at');
            $table->string('reason', 500);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->unsignedInteger('total_ht')->default(0);
            $table->unsignedInteger('vat_amount')->default(0);
            $table->unsignedInteger('total')->default(0);
            // Part remboursée au client (la facture était déjà payée)
            $table->unsignedInteger('refund_due')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'issued_at']);
        });

        Schema::create('client_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->integer('quantity')->default(1);
            $table->integer('unit_price')->default(0);
            $table->integer('amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_credit_note_lines');
        Schema::dropIfExists('client_credit_notes');
        Schema::table('client_invoices', fn (Blueprint $table) => $table->dropColumn(['vat_rate', 'vat_basis', 'total_ht', 'vat_amount', 'vat_exemption', 'credited_amount', 'client_snapshot']));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn(['legal_name', 'ncc', 'rccm', 'billing_address', 'vat_exempt', 'vat_exemption_ref', 'vat_exemption_until']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['legal_name', 'ncc', 'rccm', 'tax_regime', 'tax_office', 'vat_settings']));
    }
};
