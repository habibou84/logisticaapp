<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAssignment extends Model
{
    protected $fillable = [
        'order_id',
        'courier_id',
        'type',
        'status',
        'order_status_before',
        'assigned_by',
        'assigned_at',
        'accepted_at',
        'started_at',
        'completed_at',
        'refusal_reason',
        // planning ou auto : mission confiée sans le dispatch (null : à la main)
        'source',
    ];

    protected function casts(): array
    {
        return [
            'type' => AssignmentType::class,
            'status' => AssignmentStatus::class,
            'order_status_before' => OrderStatus::class,
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'unreachable_at' => 'datetime',
            'late_alerted_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AssignmentStatus::activeValues());
    }

    public function isActive(): bool
    {
        return in_array($this->status, AssignmentStatus::active(), true);
    }
}
