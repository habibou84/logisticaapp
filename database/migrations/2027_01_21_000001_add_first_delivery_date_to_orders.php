<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une seule date compte pour une course : sa date de livraison (elle suit les reports et devient le jour de la
 * livraison). La première date prévue est gardée à part, figée, pour l'historique et les retards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('first_delivery_date')->nullable()->after('delivery_scheduled_date');
        });

        // Première date prévue des courses existantes : la date de livraison, ou le jour de création pour une course
        // déjà reportée (la date d'avant le report n'était pas gardée)
        DB::statement(<<<'SQL'
            UPDATE orders SET first_delivery_date = CASE
                WHEN EXISTS (SELECT 1 FROM order_events e WHERE e.order_id = orders.id AND e.rescheduled_to IS NOT NULL) THEN DATE(orders.created_at)
                ELSE COALESCE(DATE(orders.delivery_scheduled_date), DATE(orders.created_at))
            END
            SQL);

        // Courses déjà livrées : la date de livraison devient le jour où elles ont été livrées
        DB::statement(<<<'SQL'
            UPDATE orders SET delivery_scheduled_date = DATE(delivered_at)
            WHERE status = 'delivered' AND delivered_at IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('first_delivery_date');
        });
    }
};
