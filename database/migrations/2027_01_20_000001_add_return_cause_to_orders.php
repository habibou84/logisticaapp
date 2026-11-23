<?php

use App\Enums\OrderEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raison du retour d'un colis (refus du client, tentatives épuisées, agence, client), retrouvée pour les colis
 * déjà en retour : l'client ne lit plus « Retour demandé » quand ce n'est pas lui qui l'a demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('return_cause', 20)->nullable()->after('return_requested');
        });

        DB::table('orders')->where('return_requested', true)->orderBy('id')->select(['id', 'attempts_count', 'max_attempts'])
            ->chunkById(500, function ($orders) {
                $ids = $orders->pluck('id');
                // Dernier échec dont le motif déclenche le retour (colis refusé)
                $refused = DB::table('order_events')->join('incident_reasons', 'incident_reasons.id', '=', 'order_events.incident_reason_id')
                    ->whereIn('order_events.order_id', $ids)->where('incident_reasons.triggers_return', true)
                    ->pluck('order_events.order_id')->flip();
                $requests = DB::table('order_events')->whereIn('order_id', $ids)->where('type', OrderEventType::ReturnRequested->value)
                    ->orderBy('id')->get(['order_id', 'actor_role'])->groupBy('order_id');

                foreach ($orders as $order) {
                    $first = $requests->get($order->id)?->first();
                    $cause = match (true) {
                        $refused->has($order->id) => 'refused',
                        $first !== null && in_array($first->actor_role, ['merchant_owner', 'merchant_staff'], true) => 'merchant',
                        $order->attempts_count >= $order->max_attempts && $order->max_attempts > 0 => 'max_attempts',
                        $first !== null => 'company',
                        default => null,
                    };
                    if ($cause !== null) {
                        DB::table('orders')->where('id', $order->id)->update(['return_cause' => $cause]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('return_cause');
        });
    }
};
