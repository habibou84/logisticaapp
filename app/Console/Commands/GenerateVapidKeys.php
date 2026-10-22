<?php

namespace App\Console\Commands;

use App\Services\Push\WebPush;
use Illuminate\Console\Command;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Génère les clés VAPID des notifications push (à copier dans .env, une seule fois)';

    public function handle(): int
    {
        $keys = WebPush::generateVapidKeys();

        $this->info('Copiez ces lignes dans .env (gardez la clé privée secrète) :');
        $this->line("VAPID_PUBLIC_KEY={$keys['public_key']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['private_key']}");
        $this->line('VAPID_SUBJECT=mailto:contact@votre-domaine.ci');
        $this->warn('Changer ces clés plus tard oblige chaque livreur à réactiver les notifications.');

        return self::SUCCESS;
    }
}
