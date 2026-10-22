<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Facturation des entreprises par la plateforme : une facture par entreprise et par mois
 * (forfait de la formule + courses en plus), paiements enregistrés par le super
 * administrateur, réglages de la plateforme (instructions de paiement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            // Mois facturé (AAAA-MM) et jours facturés (hors essai, depuis la création)
            $table->string('period', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('plan_name');
            // Forfait (au prorata des jours facturés) et courses en plus
            $table->unsignedInteger('base_amount');
            $table->unsignedInteger('included_orders')->nullable();
            $table->unsignedInteger('orders_count');
            $table->unsignedInteger('overage_orders');
            $table->unsignedInteger('overage_per_order');
            $table->unsignedInteger('overage_amount');
            $table->unsignedInteger('total');
            $table->unsignedInteger('amount_paid')->default(0);
            // open (à payer), paid (payée), void (annulée)
            $table->string('status', 10)->default('open');
            $table->date('issued_at');
            $table->date('due_at');
            $table->timestamp('paid_at')->nullable();
            // Dernier rappel envoyé (issued, due, warning, restricted)
            $table->string('last_reminder', 12)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'period']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            // mobile_money, bank_transfer, cash, other
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_payments');
        Schema::dropIfExists('platform_invoices');
    }
};
