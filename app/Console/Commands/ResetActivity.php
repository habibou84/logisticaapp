<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Remise à zéro de l'activité (avant la mise en service réelle) : toutes les courses et ce qui en découle
 * (argent, messages, suivi terrain, stock, statistiques). Restent : l'entreprise et ses réglages, les
 * utilisateurs, les clients, les livreurs, les zones et tarifs, les véhicules, les produits, l'annuaire.
 *
 * Sans --execute : simulation (compte de ce qui serait effacé). Avec --execute : confirmation « EFFACER »,
 * tout en une transaction (rien n'est effacé en cas d'erreur), puis suppression des photos et signatures.
 */
class ResetActivity extends Command
{
    protected $signature = 'app:reset-activity {--execute : Effacer vraiment (sans cette option : simulation)}';

    protected $description = 'Efface toutes les courses et ce qui en découle (argent, messages, stock, statistiques)';

    /**
     * Tables vidées, dans l'ordre (les tables qui en référencent d'autres d'abord), avec leur libellé.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        // Suivi terrain et messages
        'field_report_reminders' => 'Relances de remontées terrain',
        'field_report_reviews' => 'Traitements de remontées terrain',
        'courier_messages' => 'Messages aux livreurs',
        'order_attachments' => 'Photos et pièces jointes des courses',
        'field_event_files' => 'Photos des événements terrain',
        'field_event_messages' => 'Messages des événements terrain',
        'field_event_order' => 'Liens événements terrain – courses',
        'field_events' => 'Événements terrain',
        'inbound_messages' => 'Messages WhatsApp reçus',
        'outbound_messages' => 'Messages WhatsApp et SMS envoyés',
        'whatsapp_sessions' => 'Conversations WhatsApp en cours',
        'courier_ratings' => 'Notes des livreurs',
        'offline_actions' => 'Actions hors connexion des livreurs',
        // Stock (les quantités sont remises à zéro plus bas)
        'stock_movements' => 'Mouvements de stock',
        // Argent
        'order_expenses' => 'Frais engagés sur les courses',
        'cash_collections' => 'Encaissements des livreurs',
        'courier_earnings' => 'Gains des livreurs',
        'courier_advances' => 'Avances aux livreurs',
        'courier_payouts' => 'Paies des livreurs',
        'storage_charges' => 'Frais de stockage facturés',
        'client_supplements' => 'Suppléments facturés aux clients',
        'client_credit_note_lines' => 'Lignes d\'avoirs',
        'client_credit_notes' => 'Avoirs',
        'client_invoice_payments' => 'Paiements de factures clients',
        'client_invoice_lines' => 'Lignes de factures clients',
        'client_invoices' => 'Factures clients',
        'merchant_ledger_entries' => 'Écritures des comptes clients',
        'merchant_payouts' => 'Reversements aux clients',
        'courier_remittances' => 'Versements des livreurs à la caisse',
        'cash_closings' => 'Clôtures de caisse',
        'cash_logs' => 'Journal de caisse',
        // Courses
        'order_events' => 'Historique des courses',
        'order_assignments' => 'Attributions aux livreurs',
        'order_items' => 'Articles des courses',
        'orders' => 'Courses',
        'return_slips' => 'Bordereaux de retour',
        'department_slips' => 'Bordereaux des départements',
        'reschedule_requests' => 'Demandes de nouvelle date',
        'recipients' => 'Carnet des destinataires (créé par les courses)',
        // Statistiques et traces
        'daily_snapshots' => 'Statistiques quotidiennes',
        'client_reports' => 'Rapports clients',
        'notifications' => 'Notifications',
        'webhook_deliveries' => 'Envois de webhooks',
        'idempotency_keys' => 'Clés anti-doublon de l\'API',
    ];

    public function handle(): int
    {
        if (Company::query()->count() !== 1) {
            $this->error('Cette commande attend une seule entreprise dans la base.');

            return self::FAILURE;
        }
        $company = Company::query()->firstOrFail();
        $tables = array_filter(self::TABLES, fn ($t) => Schema::hasTable($t), ARRAY_FILTER_USE_KEY);

        $rows = [];
        foreach ($tables as $table => $label) {
            $rows[] = [$label, $table, DB::table($table)->count()];
        }
        $rows[] = ['Quantités en stock remises à zéro', 'stock_levels', DB::table('stock_levels')->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0))->count()];
        $rows[] = ['Compteurs de l\'annuaire remis à zéro', 'destinations', DB::table('destinations')->where('deliveries_count', '>', 0)->count()];

        $this->info("Entreprise : {$company->name}");
        $this->table(['Effacé', 'Table', 'Lignes'], $rows);
        $this->line('Gardés : entreprise et réglages, utilisateurs, clients, livreurs, zones et tarifs, véhicules, produits, annuaire.');

        if (! $this->option('execute')) {
            $this->warn('Simulation : rien n\'a été effacé. Pour effacer : php artisan app:reset-activity --execute');

            return self::SUCCESS;
        }

        $this->warn('Effacement DÉFINITIF. Faites d\'abord une sauvegarde sur le serveur : bash deploy/backup.sh avant-remise-a-zero');
        if ($this->ask('Tapez EFFACER pour confirmer') !== 'EFFACER') {
            $this->error('Abandon : rien n\'a été effacé.');

            return self::FAILURE;
        }

        // Fichiers à supprimer du disque, relevés avant l'effacement
        $files = DB::table('order_attachments')->get(['disk', 'path', 'original_path'])
            ->flatMap(fn ($f) => [[$f->disk, $f->path], [$f->disk, $f->original_path]])
            ->merge(DB::table('field_event_files')->get(['disk', 'path'])->map(fn ($f) => [$f->disk, $f->path]))
            ->merge(DB::table('return_slips')->get(['signature_path', 'photo_path', 'photo_original_path'])
                ->flatMap(fn ($s) => [['local', $s->signature_path], ['local', $s->photo_path], ['local', $s->photo_original_path]]))
            ->merge(DB::table('department_slips')->pluck('signature_path')->map(fn ($p) => ['local', $p]))
            ->filter(fn ($f) => filled($f[1]));

        DB::transaction(function () use ($tables) {
            foreach (array_keys($tables) as $table) {
                DB::table($table)->delete();
            }
            DB::table('stock_levels')->update(['on_hand' => 0, 'reserved' => 0]);
            DB::table('destinations')->update(['deliveries_count' => 0, 'last_used_at' => null]);
        });

        $deleted = 0;
        foreach ($files as [$disk, $path]) {
            try {
                if (Storage::disk($disk ?: 'local')->delete($path)) {
                    $deleted++;
                }
            } catch (\Throwable) {
                // Fichier déjà absent ou disque inconnu : sans conséquence
            }
        }

        $this->info("Terminé : activité effacée, {$deleted} fichier(s) supprimé(s).");

        return self::SUCCESS;
    }
}
