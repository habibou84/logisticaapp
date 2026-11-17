<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parcours d'un livreur en MAD : la routine du matin avant d'arriver chez le client (récupérer un colis dans
 * une agence, puis dans une autre, puis déposer au siège). Le livreur pointe à la première étape.
 * Écrit par l'équipe (fiche du contrat) ou par le client (son espace) ; ses changements valent tout de suite.
 */
class CourierRoutine extends Model
{
    use BelongsToCompany;

    public const SOURCES = ['staff', 'merchant'];

    protected $fillable = [
        'company_id', 'billing_plan_id', 'merchant_id', 'courier_id', 'name', 'days', 'starts_at',
        'is_active', 'source', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['days' => 'array', 'is_active' => 'boolean'];
    }

    public function billingPlan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(CourierRoutineStop::class)->orderBy('position');
    }

    /** Le parcours vaut-il ce jour de la semaine (1 = lundi … 7 = dimanche) ? */
    public function runsOn(int $dayOfWeekIso): bool
    {
        return in_array($dayOfWeekIso, array_map('intval', (array) $this->days), true);
    }

    /** Pour ce livreur (ou tous les livreurs du contrat) ? */
    public function concerns(Courier $courier): bool
    {
        return $this->courier_id === null || $this->courier_id === $courier->id;
    }
}
