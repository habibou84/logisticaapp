<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profil d'un mécanicien : en service ou non, dernière position connue (attribution des pannes au plus proche).
 */
class Mechanic extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'is_available', 'available_since', 'current_lat', 'current_lng', 'last_location_at'];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'available_since' => 'datetime',
            'current_lat' => 'float',
            'current_lng' => 'float',
            'last_location_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }

    /** Interventions acceptées et pas terminées */
    public function activeRepairs(): HasMany
    {
        return $this->repairs()->whereIn('status', Repair::IN_PROGRESS);
    }
}
