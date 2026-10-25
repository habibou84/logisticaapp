<?php

namespace App\Services\Destinations;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Destination;
use App\Models\DestinationLocation;
use App\Models\Order;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Merchants\MerchantLocation;
use Illuminate\Support\Facades\DB;

/**
 * Position confirmée sur place par le livreur (ou l'équipe) : précision minimale, la plus précise l'emporte ;
 * une position éloignée de celle déjà confirmée est gardée pour l'équipe, qui tranche (sauf réglage).
 * Elle sert aux courses suivantes : le livreur va droit à la porte.
 */
class DestinationLocations
{
    public function __construct(private readonly DestinationSettings $settings, private readonly Destinations $destinations) {}

    /**
     * @return array{outcome: string, destination: Destination, distance: ?int}
     */
    public function confirm(Destination $destination, User $user, float $lat, float $lng, ?int $accuracy, ?Order $order = null, bool $staff = false): array
    {
        $settings = $this->settings->for(Company::query()->find($destination->company_id));
        if (! $staff && $accuracy !== null && $accuracy > $settings['min_accuracy']) {
            throw new BusinessRuleException("Position trop imprécise (± {$accuracy} m, il faut ± {$settings['min_accuracy']} m) : attendez quelques secondes dehors, puis réessayez.", 'accuracy');
        }

        return DB::transaction(function () use ($destination, $user, $lat, $lng, $accuracy, $order, $staff, $settings) {
            $destination = Destination::withoutGlobalScopes()->lockForUpdate()->findOrFail($destination->id);
            $distance = $destination->hasLocation() ? (int) round(MerchantLocation::meters($destination->lat, $destination->lng, $lat, $lng)) : null;
            $outcome = match (true) {
                // L'équipe a le dernier mot
                $staff, $distance === null => 'replaced',
                $distance > $settings['conflict_distance'] => $settings['courier_overwrite'] ? 'replaced' : 'conflict',
                // Proche : la plus précise l'emporte
                $accuracy !== null && ($destination->location_accuracy === null || $accuracy < $destination->location_accuracy) => 'replaced',
                default => 'kept',
            };

            DestinationLocation::create(['destination_id' => $destination->id, 'user_id' => $user->id, 'order_id' => $order?->id,
                'lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy, 'distance' => $distance, 'outcome' => $outcome]);

            if ($outcome === 'replaced') {
                $destination->update(['lat' => $lat, 'lng' => $lng, 'location_accuracy' => $accuracy, 'location_source' => $staff ? 'staff' : 'courier',
                    'located_at' => now(), 'located_by' => $user->id, 'location_conflict' => false]);
            } elseif ($outcome === 'kept' && ! $destination->location_conflict) {
                // Confirmée à nouveau : la date compte
                $destination->update(['located_at' => now()]);
            } elseif ($outcome === 'conflict') {
                $destination->update(['location_conflict' => true]);
                $this->destinations->tellStaff($destination->company_id, 'destination_conflict', "📍 Position à vérifier : {$destination->label()}",
                    "{$user->name} la place à {$distance} m de la position connue.", "/admin/annuaire?filtre=conflict&id={$destination->id}");
            }

            return ['outcome' => $outcome, 'destination' => $destination->fresh(), 'distance' => $distance];
        });
    }

    /**
     * Position relevée sur une course : la destination de l'annuaire, sinon le destinataire du carnet du client
     * (e-commerce) ; la course elle-même aussi.
     *
     * @return array{outcome: string, destination: ?Destination, distance: ?int}|null
     */
    public function fromOrder(Order $order, User $user, float $lat, float $lng, ?int $accuracy): ?array
    {
        $order->forceFill(['delivery_lat' => $lat, 'delivery_lng' => $lng])->saveQuietly();
        if ($order->destination_id) {
            $destination = Destination::withoutGlobalScopes()->find($order->destination_id);

            return $destination ? $this->confirm($destination, $user, $lat, $lng, $accuracy, $order) : null;
        }
        if ($order->recipient_id) {
            $recipient = Recipient::withoutGlobalScopes()->find($order->recipient_id);
            $recipient?->forceFill(['lat' => $lat, 'lng' => $lng])->save();

            return ['outcome' => 'replaced', 'destination' => null, 'distance' => null];
        }

        return null;
    }
}
