<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Courses sans livreur : depuis quand la course est dans son statut, alertes déjà envoyées
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->timestamp('unassigned_alerted_at')->nullable()->after('status_changed_at');
            $table->timestamp('unassigned_escalated_at')->nullable()->after('unassigned_alerted_at');
        });
        DB::table('orders')->update(['status_changed_at' => DB::raw('updated_at')]);

        // Délais avant alerte (minutes, 0 = pas d'alerte)
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('pickup_assign_alert_minutes')->default(30)->after('parcel_hold_alert_hours');
            $table->unsignedSmallInteger('delivery_assign_alert_minutes')->default(60)->after('pickup_assign_alert_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['pickup_assign_alert_minutes', 'delivery_assign_alert_minutes']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['status_changed_at', 'unassigned_alerted_at', 'unassigned_escalated_at']));
    }
};
