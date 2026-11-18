<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Achat d'une pièce sur place (magasin fermé, pièce manquante) : demandé par le mécanicien, accordé (avec un montant
 * maximum) ou refusé par l'administrateur, puis payé par le mécanicien (prix payé, photo de la facture).
 */
class RepairPurchase extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    public const STATUS_LABELS = [self::PENDING => 'En attente de l\'accord', self::APPROVED => 'Accordé', self::REFUSED => 'Refusé'];

    protected $fillable = [
        'company_id', 'repair_id', 'mechanic_id', 'label', 'quantity', 'estimated_amount', 'reason', 'status', 'max_amount',
        'decided_by', 'decided_at', 'decision_note', 'paid_amount', 'receipt_path',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'estimated_amount' => 'integer',
            'max_amount' => 'integer',
            'paid_amount' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
