<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chef de service : un compte du client désigné chef d'un département valide les courses demandées par les agents
 * de ce département (réglage « head_approval ») avant qu'elles partent à l'entreprise de livraison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_user', function (Blueprint $table) {
            $table->boolean('is_head')->default(false);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('approval_requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_requested_at', 'approved_at']);
        });
        Schema::table('department_user', fn (Blueprint $table) => $table->dropColumn('is_head'));
    }
};
