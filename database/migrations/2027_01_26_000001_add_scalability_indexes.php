<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Index des requêtes les plus fréquentes (files du dispatch, compteurs, statistiques, recherches, purges).
 *
 * - Jour de livraison : index fonctionnel sur l'expression exacte de Order::DELIVERY_DAY_SQL (une requête qui
 *   compare cette expression s'appuie dessus, sans colonne à tenir à jour).
 * - Clés étrangères des tables chaudes : PostgreSQL ne les indexe pas de lui-même.
 * - Recherches « contient » (LIKE '%…%') : index trigramme (pg_trgm), PostgreSQL seulement.
 *
 * Créés en CONCURRENTLY (sans bloquer les écritures) : hors transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const DELIVERY_DAY = 'COALESCE(DATE(delivery_scheduled_date), DATE(created_at))';

    /** nom => [table, colonnes ou expressions] */
    private function btree(): array
    {
        return [
            'orders_company_delivery_day_index' => ['orders', 'company_id, ('.self::DELIVERY_DAY.')'],
            'orders_merchant_delivery_day_index' => ['orders', 'merchant_id, ('.self::DELIVERY_DAY.')'],
            'orders_company_status_changed_at_index' => ['orders', 'company_id, status_changed_at'],
            'orders_return_courier_id_status_index' => ['orders', 'return_courier_id, status'],
            'orders_delivery_zone_id_index' => ['orders', 'delivery_zone_id'],
            'orders_pickup_zone_id_index' => ['orders', 'pickup_zone_id'],
            'orders_recipient_id_index' => ['orders', 'recipient_id'],
            'orders_return_slip_id_index' => ['orders', 'return_slip_id'],
            'order_items_order_id_index' => ['order_items', 'order_id'],
            'order_attachments_order_id_index' => ['order_attachments', 'order_id'],
            'order_attachments_event_id_index' => ['order_attachments', 'event_id'],
            'order_events_actor_id_created_at_index' => ['order_events', 'actor_id, created_at'],
            'order_events_actor_role_created_at_index' => ['order_events', 'actor_role, created_at'],
            'order_events_assignment_id_index' => ['order_events', 'assignment_id'],
            'courier_earnings_company_id_created_at_index' => ['courier_earnings', 'company_id, created_at'],
            'courier_earnings_order_id_index' => ['courier_earnings', 'order_id'],
            'merchant_ledger_entries_company_id_created_at_index' => ['merchant_ledger_entries', 'company_id, created_at'],
            'cash_collections_company_id_index' => ['cash_collections', 'company_id'],
            'webhook_deliveries_created_at_index' => ['webhook_deliveries', 'created_at'],
        ];
    }

    /** nom => [table, expression] : recherches LIKE '%…%' (voir OrderController::index, ConsoleSearchController) */
    private function trigram(): array
    {
        return [
            'orders_tracking_code_trgm_index' => ['orders', 'LOWER(tracking_code)'],
            'orders_merchant_reference_trgm_index' => ['orders', 'LOWER(merchant_reference)'],
            'orders_recipient_name_trgm_index' => ['orders', 'LOWER(recipient_name)'],
            'orders_recipient_phone_trgm_index' => ['orders', 'recipient_phone'],
        ];
    }

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        foreach ($this->btree() as $name => [$table, $columns]) {
            DB::statement($pgsql
                ? "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} ({$columns})"
                : "CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
        }

        if (! $pgsql) {
            return;
        }

        // pg_trgm est une extension « de confiance » (PostgreSQL 13+) : le propriétaire de la base peut l'activer.
        // Sans elle, les recherches restent correctes, seulement plus lentes : la mise à jour ne doit pas échouer.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning('Extension pg_trgm indisponible : index de recherche non créés', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($this->trigram() as $name => [$table, $expression]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} USING gin (({$expression}) gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        foreach ([...array_keys($this->btree()), ...array_keys($this->trigram())] as $name) {
            DB::statement($pgsql ? "DROP INDEX CONCURRENTLY IF EXISTS {$name}" : "DROP INDEX IF EXISTS {$name}");
        }
    }
};
