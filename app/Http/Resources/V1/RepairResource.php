<?php

namespace App\Http\Resources\V1;

use App\Models\FieldEventFile;
use App\Models\FieldEventMessage;
use App\Models\Repair;
use App\Models\RepairFile;
use App\Models\RepairPurchase;
use App\Services\FieldEvents\FieldEventAlerts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Intervention de l'atelier, pour le mécanicien (application /mecanicien) et pour l'équipe (Atelier).
 *
 * @mixin Repair
 */
class RepairResource extends JsonResource
{
    /** Relations chargées pour l'affichage complet */
    public const RELATIONS = ['mechanic.user', 'courier.user', 'vehicle', 'files', 'purchases.decider', 'fieldEvent.files', 'fieldEvent.messages.sender'];

    public function toArray(Request $request): array
    {
        $mechanicView = (bool) $request->user()?->isMechanic();
        $base = $mechanicView ? "/mechanic/repairs/{$this->id}" : "/repairs/{$this->id}";
        $me = $mechanicView ? $request->user()->mechanic : null;

        return [
            'id' => $this->id,
            'number' => str_pad((string) $this->id, 4, '0', STR_PAD_LEFT),
            'status' => $this->status,
            'status_label' => Repair::STATUS_LABELS[$this->status] ?? $this->status,
            'mine' => $me !== null && $this->mechanic_id === $me->id,
            'source' => $this->source,
            'title' => $this->title,
            'icon' => $this->icon,
            'severity' => $this->severity,
            'severity_label' => FieldEventAlerts::SEVERITY_LABELS[$this->severity] ?? $this->severity,
            'note' => $this->note,
            'lat' => $this->lat,
            'lng' => $this->lng,
            // Distance à vol d'oiseau depuis la dernière position du mécanicien
            'distance_km' => $me && $this->lat !== null && $me->current_lat !== null ? round(self::km($this->lat, $this->lng, $me->current_lat, $me->current_lng), 1) : null,
            'created_at' => $this->created_at,
            'offered_at' => $this->offered_at,
            'offer_expires_at' => $this->offer_expires_at,
            'escalated_at' => $this->escalated_at,
            'declined_count' => count($this->declined ?? []),
            'accepted_at' => $this->accepted_at,
            'departed_at' => $this->departed_at,
            'arrived_at' => $this->arrived_at,
            'arrival_lat' => $this->arrival_lat,
            'arrival_lng' => $this->arrival_lng,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'cancelled_at' => $this->cancelled_at,
            'category' => $this->category,
            'category_label' => $this->category_label,
            'part' => $this->part,
            'diagnosis' => $this->diagnosis,
            'decision' => $this->decision,
            'decision_label' => $this->decision ? Repair::DECISIONS[$this->decision] ?? $this->decision : null,
            'cause' => $this->cause,
            'cause_label' => $this->cause ? Repair::CAUSES[$this->cause] ?? $this->cause : null,
            'parts' => $this->parts ?? [],
            'parts_amount' => $this->parts_amount,
            'garage' => $this->garage,
            'garage_amount' => $this->garage_amount,
            'total' => $this->total(),
            'odometer' => $this->odometer,
            'remark' => $this->remark,
            'courier_can_resume' => $this->courier_can_resume,
            'mechanic' => $this->whenLoaded('mechanic', fn () => $this->mechanic ? [
                'id' => $this->mechanic->id,
                'name' => $this->mechanic->user?->name,
                'phone' => $this->mechanic->user?->phone,
            ] : null),
            'courier' => $this->whenLoaded('courier', fn () => $this->courier ? [
                'id' => $this->courier->id,
                'name' => $this->courier->user?->name,
                'phone' => $this->courier->user?->phone,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'plate' => $this->vehicle->plate,
                'name' => trim($this->vehicle->brand.' '.$this->vehicle->model) ?: null,
                'type' => $this->vehicle->type,
                'odometer' => $this->vehicle->odometer,
                'status' => $this->vehicle->status,
            ] : null),
            'files' => $this->whenLoaded('files', fn () => $this->files->map(fn (RepairFile $f) => [
                'id' => $f->id,
                'kind' => $f->kind,
                'url' => "{$base}/files/{$f->id}",
            ])->values()),
            'purchases' => $this->whenLoaded('purchases', fn () => $this->purchases->map(fn (RepairPurchase $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'quantity' => $p->quantity,
                'estimated_amount' => $p->estimated_amount,
                'reason' => $p->reason,
                'status' => $p->status,
                'status_label' => RepairPurchase::STATUS_LABELS[$p->status] ?? $p->status,
                'max_amount' => $p->max_amount,
                'decided_by' => $p->decider?->name,
                'decided_at' => $p->decided_at,
                'decision_note' => $p->decision_note,
                'paid_amount' => $p->paid_amount,
                'has_receipt' => $p->receipt_path !== null,
                'created_at' => $p->created_at,
            ])->values()),
            // Signalement du livreur : sa note vocale, ses photos, l'échange avec lui
            'event' => $this->whenLoaded('fieldEvent', fn () => $this->fieldEvent ? [
                'id' => $this->fieldEvent->id,
                'status' => $this->fieldEvent->status,
                'capacity_label' => FieldEventAlerts::CAPACITY_LABELS[$this->fieldEvent->capacity] ?? $this->fieldEvent->capacity,
                'orders_count' => $this->fieldEvent->orders()->count(),
                'files' => $this->fieldEvent->relationLoaded('files') ? $this->fieldEvent->files->map(fn (FieldEventFile $f) => [
                    'id' => $f->id,
                    'kind' => $f->kind,
                    'duration' => $f->duration,
                    'url' => $mechanicView ? "{$base}/event-files/{$f->id}" : "/field-events/{$this->fieldEvent->id}/files/{$f->id}",
                ])->values() : [],
                'messages' => $this->fieldEvent->relationLoaded('messages')
                    ? $this->fieldEvent->messages->map(fn (FieldEventMessage $m) => $m->present())->values() : [],
            ] : null),
        ];
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
