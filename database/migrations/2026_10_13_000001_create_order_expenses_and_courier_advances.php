<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frais engagés pour une course : gare, transport, emballage, stationnement…
        Schema::create('order_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained();
            $table->string('type', 20); // shipping | transport | packaging | parking | other
            $table->string('label')->nullable();
            $table->unsignedInteger('amount');
            // Qui a payé : le livreur (de sa poche ou avec une avance de la caisse) ou l'agence
            $table->string('paid_by', 20)->default('courier'); // courier | company
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            // Qui supporte le coût : le marchand (déduit de son point) ou l'agence
            $table->string('billed_to', 20)->default('merchant'); // merchant | company
            $table->foreignId('ledger_entry_id')->nullable()->constrained('merchant_ledger_entries')->nullOnDelete();
            // Remboursement du livreur : réglé lors de ce versement à la caisse
            $table->foreignId('remittance_id')->nullable()->constrained('courier_remittances')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['courier_id', 'remittance_id']);
        });

        // Argent remis par la caisse à un livreur avant une mission (ex. frais de gare)
        Schema::create('courier_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained();
            $table->unsignedInteger('amount');
            $table->string('reason');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('given_at');
            $table->foreignId('remittance_id')->nullable()->constrained('courier_remittances')->nullOnDelete();
            $table->timestamps();

            $table->index(['courier_id', 'remittance_id']);
        });

        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->integer('total_other_fees')->default(0)->after('total_shipping_fees');
        });

        // Reprise des frais de gare enregistrés sur les encaissements
        $rows = DB::table('cash_collections')
            ->join('orders', 'orders.id', '=', 'cash_collections.order_id')
            ->where('cash_collections.courier_expense', '>', 0)
            ->get(['cash_collections.*', 'orders.company_id as order_company_id']);

        foreach ($rows as $row) {
            DB::table('order_expenses')->insert([
                'company_id' => $row->order_company_id,
                'order_id' => $row->order_id,
                'type' => 'shipping',
                'amount' => $row->courier_expense,
                'paid_by' => 'courier',
                'courier_id' => $row->courier_id,
                'billed_to' => 'merchant',
                'ledger_entry_id' => DB::table('merchant_ledger_entries')
                    ->where('order_id', $row->order_id)->where('type', 'shipping_fee')->value('id'),
                'remittance_id' => $row->remittance_id,
                'created_at' => $row->collected_at,
                'updated_at' => $row->collected_at,
            ]);
        }

        // Lignes créées uniquement pour porter des frais (rien d'encaissé)
        DB::table('cash_collections')->where('amount_collected', 0)->delete();

        Schema::table('cash_collections', fn (Blueprint $table) => $table->dropColumn('courier_expense'));
    }

    public function down(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->unsignedInteger('courier_expense')->default(0)->after('amount_collected');
        });
        Schema::table('merchant_payouts', fn (Blueprint $table) => $table->dropColumn('total_other_fees'));
        Schema::dropIfExists('courier_advances');
        Schema::dropIfExists('order_expenses');
    }
};
