<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture mensuelle d'une entreprise (abonnement à la plateforme).
 */
class PlatformInvoice extends Model
{
    public const OPEN = 'open';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected $fillable = [
        'company_id', 'number', 'period', 'period_start', 'period_end', 'plan_name',
        'base_amount', 'included_orders', 'orders_count', 'overage_orders', 'overage_per_order', 'overage_amount',
        'total', 'amount_paid', 'status', 'issued_at', 'due_at', 'paid_at', 'last_reminder', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'issued_at' => 'date:Y-m-d',
            'due_at' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'base_amount' => 'integer',
            'included_orders' => 'integer',
            'orders_count' => 'integer',
            'overage_orders' => 'integer',
            'overage_per_order' => 'integer',
            'overage_amount' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    /**
     * Jours de retard (0 avant l'échéance comprise).
     */
    public function daysLate(?string $today = null): int
    {
        $today ??= now($this->company?->timezone ?? config('app.timezone'))->toDateString();

        return $this->status === self::OPEN && $this->due_at->toDateString() < $today
            ? (int) $this->due_at->diffInDays($today)
            : 0;
    }
}
