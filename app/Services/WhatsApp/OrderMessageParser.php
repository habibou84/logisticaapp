<?php

namespace App\Services\WhatsApp;

use App\Models\Zone;
use Illuminate\Support\Collection;

/**
 * Extrait les informations d'une course d'un message libre de marchand.
 *
 * Résultat : champs trouvés seulement, parmi recipient_name, recipient_phone,
 * recipient_phone2, zones (candidates, la plus probable en premier), delivery_address,
 * delivery_landmark, items_amount, fee_payer (merchant|recipient), description, merchant_note.
 */
interface OrderMessageParser
{
    /**
     * @param  Collection<int, Zone>  $zones
     * @return array<string, mixed>
     */
    public function parse(string $text, Collection $zones, ?string $senderPhone = null): array;
}
