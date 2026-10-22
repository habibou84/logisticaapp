<?php

namespace App\Http\Resources\V1;

use App\Models\OrderEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderEvent
 */
class OrderEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta ?? [];

        // Le marchand ne voit pas les identifiants internes
        if ($request->user()?->merchant_id !== null) {
            $meta = array_intersect_key($meta, array_flip(['type', 'type_label', 'courier_name', 'changes', 'collected_amount', 'source', 'shipping_fee', 'shipping_carrier', 'shipping_reference', 'channel']));
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'from_status' => $this->from_status,
            'from_status_label' => $this->from_status?->label(),
            'to_status' => $this->to_status,
            'to_status_label' => isset($meta['shipping_carrier']) ? 'Expédié' : $this->to_status?->label(),
            'incident_reason' => $this->incident_reason_id ? [
                'id' => $this->incident_reason_id,
                'label' => $this->incidentReason?->label,
            ] : null,
            'rescheduled_to' => $this->rescheduled_to?->toDateString(),
            'note' => $this->note,
            'actor_type' => $this->actor_type,
            'actor_name' => $this->actor_name,
            'actor_role' => $this->actor_role,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'meta' => (object) $meta,
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'url' => route('v1.orders.attachments.show', [$this->order_id, $a->id], false),
            ])),
            'visible_to_merchant' => $this->visible_to_merchant,
            'created_at' => $this->created_at,
        ];
    }
}
