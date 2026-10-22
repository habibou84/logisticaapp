<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Garde physique du colis : livreur qui l'a en main (ramassé, en livraison, échec ou report non rendu)
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('held_by_courier_id')->nullable()->after('return_courier_id')->constrained('couriers')->nullOnDelete();
            $table->timestamp('held_since')->nullable()->after('held_by_courier_id');
            $table->timestamp('hold_alerted_at')->nullable()->after('held_since');
            $table->index(['held_by_courier_id', 'held_since']);
        });

        // Délai au-delà duquel un colis encore chez un livreur est signalé au dispatch (0 = jamais)
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('parcel_hold_alert_hours')->default(24)->after('field_alert_reminder_minutes');
        });

        // Colis rendus et colis gardés lors du point de caisse
        Schema::table('courier_remittances', function (Blueprint $table) {
            $table->json('parcels')->nullable()->after('notes');
        });

        // Reprise de l'existant : le colis est chez le dernier livreur concerné
        foreach ([
            'pickup_courier_id' => ['picked_up'],
            'delivery_courier_id' => ['out_for_delivery', 'delivery_failed', 'rescheduled'],
            'return_courier_id' => ['returning'],
        ] as $column => $statuses) {
            DB::table('orders')->whereIn('status', $statuses)->whereNotNull($column)->whereNull('held_by_courier_id')
                ->update(['held_by_courier_id' => DB::raw($column), 'held_since' => DB::raw('updated_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('courier_remittances', fn (Blueprint $table) => $table->dropColumn('parcels'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('parcel_hold_alert_hours'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['held_by_courier_id', 'held_since']);
            $table->dropConstrainedForeignId('held_by_courier_id');
            $table->dropColumn(['held_since', 'hold_alerted_at']);
        });
    }
};
