<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alerte « livreur libre / bientôt libre » : une par livreur et par palier dans la journée.
     */
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->date('availability_alerted_on')->nullable()->after('last_location_at');
            $table->string('availability_alert_level', 10)->nullable()->after('availability_alerted_on');
        });
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->dropColumn(['availability_alerted_on', 'availability_alert_level']);
        });
    }
};
