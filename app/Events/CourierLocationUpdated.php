<?php

namespace App\Events;

use App\Models\Courier;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nouvelle position (ou disponibilité) d'un livreur, diffusée au personnel
 * de l'entreprise pour la carte des livreurs en direct.
 */
class CourierLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Courier $courier) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('company.'.$this->courier->company_id)];
    }

    public function broadcastAs(): string
    {
        return 'courier.location';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->courier->id,
            'is_available' => $this->courier->is_available,
            'lat' => $this->courier->current_lat,
            'lng' => $this->courier->current_lng,
            'last_location_at' => $this->courier->last_location_at?->toIso8601String(),
        ];
    }
}
