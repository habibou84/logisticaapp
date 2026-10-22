<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneNotifications extends Command
{
    /** Notifications lues gardées dans la cloche (jours) */
    public const KEEP_READ_DAYS = 30;

    /** Notifications non lues gardées (jours) : au-delà, elles ne sont plus utiles */
    public const KEEP_UNREAD_DAYS = 90;

    protected $signature = 'notifications:prune';

    protected $description = 'Supprime les anciennes notifications de la cloche (lues depuis 30 jours, toutes au-delà de 90 jours)';

    public function handle(): int
    {
        $deleted = DB::table('notifications')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('read_at')->where('created_at', '<', now()->subDays(self::KEEP_READ_DAYS)))
                ->orWhere('created_at', '<', now()->subDays(self::KEEP_UNREAD_DAYS)))
            ->delete();

        $this->info($deleted.' notification(s) supprimée(s).');

        return self::SUCCESS;
    }
}
