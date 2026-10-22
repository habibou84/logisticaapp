<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Motifs d'incident. company_id null = motif commun à toutes les entreprises.
 */
class IncidentReason extends Model
{
    protected $fillable = [
        'company_id',
        'code',
        'label',
        'applies_to',
        'requires_date',
        'counts_as_attempt',
        'triggers_return',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_date' => 'boolean',
            'counts_as_attempt' => 'boolean',
            'triggers_return' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeAvailableTo(Builder $query, ?int $companyId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    public function appliesTo(string $stage): bool
    {
        return $this->applies_to === 'both' || $this->applies_to === $stage;
    }
}
