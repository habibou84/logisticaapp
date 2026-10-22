<?php

namespace App\Http\Resources\V1;

use App\Enums\AssignmentType;
use App\Models\OrderAssignment;
use App\Services\Orders\PickupGroups;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderAssignment
 */
class OrderAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'status' => $this->status,
            'courier_id' => $this->courier_id,
            'courier_name' => $this->whenLoaded('courier', fn () => $this->courier->user?->name),
            'assigned_by_name' => $this->whenLoaded('assignedBy', fn () => $this->assignedBy?->name),
            'assigned_at' => $this->assigned_at,
            'accepted_at' => $this->accepted_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'refusal_reason' => $this->refusal_reason,
            // planning : confiée automatiquement (acceptée d'office)
            'source' => $this->source,
            'can_report_impediment' => $this->when($this->resource->hasAttribute('can_report_impediment'), fn () => $this->resource->getAttribute('can_report_impediment')),
            'unread_messages' => $this->when($this->resource->getAttribute('unread_messages') !== null, fn () => $this->resource->getAttribute('unread_messages')),
            // Espace livreur : gain attendu (mission en cours) ou acquis (mission terminée)
            'gain' => $this->when($this->resource->hasAttribute('gain'), fn () => $this->resource->getAttribute('gain')),
            // Ramassage : même client, même adresse → regroupés en une seule mission dans l'application
            'pickup_group' => $this->when(
                $this->type === AssignmentType::Pickup && $this->relationLoaded('order'),
                fn () => PickupGroups::key($this->order),
            ),
            'order' => OrderResource::make($this->whenLoaded('order')),
        ];
    }
}
