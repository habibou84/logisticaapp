<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report ou retour signalé par le livreur avec un motif « à valider » : il ne prend effet qu'une fois validé par
 * l'équipe ou par le client. Refusé, la course reste en échec et l'équipe décide de la suite.
 */
class OrderProposal extends Model
{
    use BelongsToCompany;

    public const RESCHEDULE = 'reschedule';

    public const RETURN = 'return';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    // Une autre décision a été prise sur la course (nouvelle livraison, retour décidé autrement…)
    public const CLOSED = 'closed';

    protected $fillable = [
        'company_id', 'order_id', 'order_event_id', 'incident_reason_id', 'proposed_by', 'kind', 'proposed_date', 'proposed_time', 'status',
        'decided_by', 'decided_by_type', 'decided_at', 'decision_note', 'reminders', 'last_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_date' => 'date',
            'decided_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'reminders' => 'integer',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** « Report au jeudi 3 décembre », « Retour au client » */
    public function summary(): string
    {
        return $this->kind === self::RESCHEDULE
            ? 'Report au '.($this->proposed_date?->locale('fr')->isoFormat('dddd D MMMM') ?? '?').($this->proposed_time ? ' à '.self::hour($this->proposed_time) : '')
            : 'Retour au client';
    }

    /** « 17:30 » → « 17h30 », « 09:00 » → « 9h » */
    public static function hour(?string $time): string
    {
        if (! $time || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return (string) $time;
        }

        return (int) $m[1].'h'.($m[2] === '00' ? '' : $m[2]);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'À valider',
            self::APPROVED => 'Validé',
            self::REJECTED => 'Refusé',
            default => 'Remplacé par une autre décision',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'summary' => $this->summary(),
            'proposed_date' => $this->proposed_date?->toDateString(),
            'proposed_time' => $this->proposed_time,
            'reason' => $this->reason?->label,
            'note' => $this->event?->note,
            'proposed_by' => $this->proposer?->name,
            'created_at' => $this->created_at,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'decided_by' => $this->decider?->name,
            'decided_by_type' => $this->decided_by_type,
            'decided_at' => $this->decided_at,
            'decision_note' => $this->decision_note,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderEvent::class, 'order_event_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(IncidentReason::class, 'incident_reason_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
