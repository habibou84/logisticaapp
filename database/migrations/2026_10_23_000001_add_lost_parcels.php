<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Colis déclaré perdu (statut « lost ») : date et circonstances
            $table->timestamp('lost_at')->nullable()->after('cancelled_at');
            $table->string('lost_reason', 500)->nullable()->after('lost_at');
            // Alerte aux administrateurs : colis chez un livreur depuis trois fois le délai, peut-être perdu
            $table->timestamp('hold_escalated_at')->nullable()->after('hold_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['lost_at', 'lost_reason', 'hold_escalated_at']));
    }
};
