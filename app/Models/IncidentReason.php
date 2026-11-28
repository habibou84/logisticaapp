<?php

namespace App\Models;

use App\Enums\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Motifs d'incident (non-livraison, ramassage) et notes de suivi de l'équipe (applies_to « follow_up »).
 * company_id null = motif commun ; l'administrateur les règle (Réglages › Motifs).
 */
class IncidentReason extends Model
{
    public const STAGES = ['pickup', 'delivery', 'both', 'follow_up'];

    /** Familles des motifs (regroupement à l'écran du livreur) */
    public const FAMILIES = [
        'unavailable' => 'Indisponible',
        'postponed' => 'Report',
        'address' => 'Adresse, contact',
        'documents' => 'Documents',
        'recipient' => 'Destinataire',
        'info' => 'Information (la course continue)',
        'pickup' => 'Ramassage',
        'other' => 'Autre',
    ];

    public const PHOTO = ['off', 'optional', 'required'];

    /** status : échec, report ou retour (la course change de statut) ; note : simple information, la course continue */
    public const EFFECTS = ['status', 'note'];

    /** Effet sur la fiche de l'annuaire */
    public const DESTINATION_EFFECTS = ['inactive', 'check_name', 'check_address', 'check_phone'];

    protected $fillable = [
        'company_id',
        'code',
        'label',
        'applies_to',
        'family',
        'activities',
        'requires_date',
        'requires_note',
        'photo',
        'counts_as_attempt',
        'triggers_return',
        'destination_effect',
        'holds',
        'visible_to_client',
        'effect',
        'needs_validation',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'activities' => 'array',
            'requires_date' => 'boolean',
            'requires_note' => 'boolean',
            'counts_as_attempt' => 'boolean',
            'triggers_return' => 'boolean',
            'holds' => 'boolean',
            'visible_to_client' => 'boolean',
            'needs_validation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeAvailableTo(Builder $query, ?int $companyId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    /** Simple information : rien ne change sur la course */
    public function isNote(): bool
    {
        return $this->effect === 'note';
    }

    /** Report ou retour du livreur à faire valider (équipe ou client) avant qu'il prenne effet */
    public function validates(): bool
    {
        return $this->needs_validation && ! $this->isNote() && ($this->requires_date || $this->triggers_return);
    }

    public function appliesTo(string $stage): bool
    {
        return $this->applies_to === 'both' || $this->applies_to === $stage;
    }

    /** Proposé pour cette activité (aucune activité choisie : toutes) */
    public function forActivity(Activity|string|null $activity): bool
    {
        $value = $activity instanceof Activity ? $activity->value : $activity;

        return empty($this->activities) || $value === null || in_array($value, $this->activities, true);
    }

    public function isFollowUp(): bool
    {
        return $this->applies_to === 'follow_up';
    }
}
