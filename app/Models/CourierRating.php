<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note d'un livreur : par un client (course ou mise à disposition), par l'équipe (course ou évaluation)
 * ou par un destinataire. Une note neutralisée par un responsable ne compte plus.
 */
class CourierRating extends Model
{
    use BelongsToCompany;

    public const CLIENT = 'client';

    public const TEAM = 'team';

    public const RECIPIENT = 'recipient';

    public const COURSE = 'course';

    public const PERIOD = 'period';

    protected $fillable = [
        'company_id', 'courier_id', 'order_id', 'merchant_id', 'source', 'scope', 'author_id', 'stars', 'criteria', 'reasons',
        'comment', 'period_start', 'period_end', 'weight', 'neutralized_at', 'neutralized_by', 'neutralized_reason',
    ];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'criteria' => 'array',
            'reasons' => 'array',
            'weight' => 'float',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'neutralized_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Notes qui comptent (pas neutralisées) */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('neutralized_at');
    }
}
