<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sécurité de la caisse : un paiement « reçu par l'entreprise » (Wave, Mobile Money) annoncé par un livreur reste à
 * sa charge tant que la caisse ne l'a pas confirmé ; des frais déclarés par un livreur n'ont d'effet qu'une fois
 * validés. Ce qui existe déjà est considéré comme confirmé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->timestamp('company_confirmed_at')->nullable()->after('transaction_ref');
            $table->foreignId('confirmed_by')->nullable()->after('company_confirmed_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('order_expenses', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('created_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        DB::table('cash_collections')->where('received_by_company', true)->update(['company_confirmed_at' => DB::raw('collected_at')]);
        DB::table('order_expenses')->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('company_confirmed_at');
        });
        Schema::table('order_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
