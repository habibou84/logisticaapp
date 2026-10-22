<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Validation des courses : manuelle, automatique ou automatique avec règles
     * (heure limite, journée fermée par le dispatch → livraison le lendemain).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('validation_mode', 10)->default('manual')->after('auto_confirm_orders');
            // Journée fermée à la main par le dispatch (date locale et heure de fermeture)
            $table->date('deliveries_closed_on')->nullable()->after('daily_cutoff_time');
            $table->timestamp('deliveries_closed_at')->nullable()->after('deliveries_closed_on');
        });

        DB::table('companies')->where('auto_confirm_orders', true)->update(['validation_mode' => 'auto']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['validation_mode', 'deliveries_closed_on', 'deliveries_closed_at']);
        });
    }
};
