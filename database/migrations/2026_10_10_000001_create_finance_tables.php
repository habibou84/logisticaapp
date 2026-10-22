<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Part des frais de livraison facturée au marchand quand le colis lui est retourné
            $table->unsignedSmallInteger('return_fee_percent')->default(100)->after('require_delivery_code');
        });

        Schema::table('couriers', function (Blueprint $table) {
            // Rémunération par course (en FCFA) : 0 = non rémunéré à la course (salarié)
            $table->unsignedInteger('pickup_commission')->default(0)->after('vehicle_plate');
            $table->unsignedInteger('delivery_commission')->default(0)->after('pickup_commission');
            $table->unsignedInteger('return_commission')->default(0)->after('delivery_commission');
        });

        Schema::create('courier_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained();
            $table->unsignedInteger('amount_expected');
            $table->unsignedInteger('amount_received');
            $table->integer('difference'); // négatif = manque
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'received_at']);
            $table->index(['courier_id', 'received_at']);
        });

        Schema::create('cash_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_expected');
            $table->unsignedInteger('amount_collected');
            $table->string('method', 20)->default('cash'); // cash | wave | orange_money | mtn_momo | moov_money
            // Paiement mobile reçu directement sur le compte de l'entreprise : rien à verser
            $table->boolean('received_by_company')->default(false);
            $table->string('transaction_ref')->nullable();
            $table->timestamp('collected_at');
            $table->foreignId('remittance_id')->nullable()->constrained('courier_remittances')->nullOnDelete();
            $table->timestamps();

            $table->index(['courier_id', 'remittance_id']);
        });

        Schema::create('merchant_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->string('reference', 30)->unique();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->integer('total_collected')->default(0);
            $table->integer('total_fees')->default(0);
            $table->integer('total_adjustments')->default(0);
            $table->integer('net_amount'); // négatif = le marchand doit payer l'entreprise
            $table->string('status', 20)->default('draft'); // draft | paid | cancelled
            $table->string('method', 20)->nullable();
            $table->string('transaction_ref')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['merchant_id', 'created_at']);
        });

        // Grand livre marchand : insertions uniquement, solde = somme des montants
        Schema::create('merchant_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->string('type', 20); // cod_credit | delivery_fee | return_fee | adjustment | payout
            $table->integer('amount'); // + crédit du marchand, - débit
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Reversement qui solde cette écriture (null = pas encore reversée)
            $table->foreignId('payout_id')->nullable()->constrained('merchant_payouts')->nullOnDelete();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['merchant_id', 'payout_id']);
            $table->index(['merchant_id', 'created_at']);
            $table->index('order_id');
        });

        Schema::create('courier_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained();
            $table->string('reference', 30)->unique();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->integer('amount');
            $table->string('status', 20)->default('draft'); // draft | paid | cancelled
            $table->string('method', 20)->nullable();
            $table->string('transaction_ref')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // Gains des livreurs : insertions uniquement
        Schema::create('courier_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained();
            $table->string('type', 20); // pickup | delivery | return | shortfall | adjustment
            $table->integer('amount');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('remittance_id')->nullable()->constrained('courier_remittances')->nullOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained('courier_payouts')->nullOnDelete();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['courier_id', 'payout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_earnings');
        Schema::dropIfExists('courier_payouts');
        Schema::dropIfExists('merchant_ledger_entries');
        Schema::dropIfExists('merchant_payouts');
        Schema::dropIfExists('cash_collections');
        Schema::dropIfExists('courier_remittances');
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn(['pickup_commission', 'delivery_commission', 'return_commission']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('return_fee_percent'));
    }
};
