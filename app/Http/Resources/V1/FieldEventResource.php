<?php

namespace App\Http\Resources\V1;

use App\Models\FieldEvent;
use App\Models\FieldEventFile;
use App\Models\FieldEventMessage;
use App\Models\Order;
use App\Models\Repair;
use App\Services\FieldEvents\FieldEventAlerts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Événement terrain, pour l'équipe (Remontées terrain) et pour le livreur (son signalement).
 *
 * @mixin FieldEvent
 */
class FieldEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $courierView = (bool) $request->user()?->isCourier();
        $base = $courierView ? '/courier/field-events' : '/field-events';

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'icon' => $this->icon,
            'severity' => $this->severity,
            'severity_label' => FieldEventAlerts::SEVERITY_LABELS[$this->severity] ?? $this->severity,
            'capacity' => $this->capacity,
            'capacity_label' => FieldEventAlerts::CAPACITY_LABELS[$this->capacity] ?? $this->capacity,
            'blocked_until' => $this->blocked_until,
            'note' => $this->note,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'cost' => $this->cost,
            'status' => $this->status,
            'excused' => $this->excused,
            'created_at' => $this->created_at,
            'taken_at' => $this->taken_at,
            'taken_by' => $this->whenLoaded('taker', fn () => $this->taker?->name),
            'resolved_at' => $this->resolved_at,
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'resolution' => $this->resolution,
            'resumed_at' => $this->resumed_at,
            'effects' => $courierView ? null : ($this->effects ?? []),
            'courier' => $this->whenLoaded('courier', fn () => [
                'id' => $this->courier->id,
                'name' => $this->courier->user?->name,
                'phone' => $this->courier->user?->phone,
                'is_available' => $this->courier->is_available,
            ]),
            'orders' => $this->whenLoaded('orders', fn () => $this->orders->map(fn (Order $o) => [
                'id' => $o->id,
                'tracking_code' => $o->tracking_code,
                'status' => $o->status->value,
                'status_label' => $o->statusLabel(),
                'merchant' => $o->merchant?->business_name,
                'recipient_name' => $o->recipient_name,
                'zone' => $o->deliveryZone?->name,
                'held_by_courier' => $o->held_by_courier_id === $this->courier_id,
                'courier_still_assigned' => in_array($this->courier_id, [$o->pickup_courier_id, $o->delivery_courier_id, $o->return_courier_id], true),
            ])->values()),
            'files' => $this->whenLoaded('files', fn () => $this->files->map(fn (FieldEventFile $f) => [
                'id' => $f->id,
                'kind' => $f->kind,
                'duration' => $f->duration,
                'url' => "{$base}/{$this->id}/files/{$f->id}",
            ])->values()),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn (FieldEventMessage $m) => $m->present())->values()),
            // Mécanicien envoyé sur la panne (Atelier)
            'repair' => $this->whenLoaded('repair', fn () => $this->repair ? [
                'id' => $this->repair->id,
                'status' => $this->repair->status,
                'status_label' => Repair::STATUS_LABELS[$this->repair->status] ?? $this->repair->status,
                // Mécanicien connu une fois qu'il a accepté (une proposition peut encore passer à un autre)
                'mechanic' => $this->repair->mechanic?->user && ! in_array($this->repair->status, Repair::WAITING, true)
                    ? ['name' => $this->repair->mechanic->user->name, 'phone' => $this->repair->mechanic->user->phone] : null,
                'accepted_at' => $this->repair->accepted_at,
                'departed_at' => $this->repair->departed_at,
                'arrived_at' => $this->repair->arrived_at,
                'finished_at' => $this->repair->finished_at,
                'decision_label' => $this->repair->decision ? Repair::DECISIONS[$this->repair->decision] : null,
            ] : null),
        ];
    }

    /** Relations chargées pour l'affichage complet */
    public const RELATIONS = ['courier.user', 'orders.merchant', 'orders.deliveryZone', 'files', 'messages.sender', 'taker', 'resolver', 'repair.mechanic.user'];
}
