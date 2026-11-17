<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape d'un parcours : un lieu de l'annuaire (agence du client…) ou le siège du client, et ce qu'on y fait.
 */
class CourierRoutineStop extends Model
{
    public const PLACES = ['destination', 'merchant'];

    public const ACTIONS = ['pickup', 'drop', 'visit'];

    public const ACTION_LABELS = ['pickup' => 'Récupérer', 'drop' => 'Déposer', 'visit' => 'Passer'];

    protected $fillable = ['courier_routine_id', 'position', 'place_type', 'destination_id', 'action', 'instructions', 'expected_time'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(CourierRoutine::class, 'courier_routine_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * Lieu de l'étape : nom, adresse et position (celle de la destination, ou du siège du client).
     *
     * @return array{label: string, address: string|null, lat: float|null, lng: float|null}
     */
    public function place(?Merchant $merchant): array
    {
        if ($this->place_type === 'merchant') {
            return [
                'label' => 'Siège · '.($merchant?->business_name ?? 'client'),
                'address' => $merchant ? trim(implode(', ', array_filter([$merchant->pickup_address, $merchant->pickup_landmark]))) ?: null : null,
                'lat' => $merchant?->pickup_lat !== null ? (float) $merchant->pickup_lat : null,
                'lng' => $merchant?->pickup_lng !== null ? (float) $merchant->pickup_lng : null,
            ];
        }
        $d = $this->destination;

        return [
            'label' => $d?->label() ?? 'Lieu supprimé',
            'address' => $d ? trim(implode(', ', array_filter([$d->district, $d->address, $d->landmark]))) ?: null : null,
            'lat' => $d?->lat,
            'lng' => $d?->lng,
        ];
    }
}
