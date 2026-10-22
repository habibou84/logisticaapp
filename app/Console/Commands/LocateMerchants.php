<?php

namespace App\Console\Commands;

use App\Services\Merchants\MerchantLocation;
use Illuminate\Console\Command;

class LocateMerchants extends Command
{
    protected $signature = 'merchants:locate';

    protected $description = 'Estime la position de ramassage des marchands qui n\'en ont pas, d\'après les ramassages des livreurs';

    public function handle(MerchantLocation $locations): int
    {
        $this->info($locations->learnAll().' marchand(s) localisé(s) d\'après les ramassages.');

        return self::SUCCESS;
    }
}
