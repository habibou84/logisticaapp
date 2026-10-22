<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Point du trajet d'un livreur. Conservé 90 jours.
 */
class CourierLocation extends Model
{
    use BelongsToCompany, MassPrunable;

    public const RETENTION_DAYS = 90;

    public $timestamps = false;

    protected $fillable = ['company_id', 'courier_id', 'lat', 'lng', 'accuracy', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function prunable()
    {
        return static::withoutGlobalScopes()->where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * Distance en mètres entre deux points (formule de haversine).
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
