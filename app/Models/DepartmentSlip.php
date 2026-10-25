<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bordereau d'un département pour une journée : ce qu'il a remis au livreur (pickup) ou reçu (delivery),
 * signé dans l'application par la personne du département, ou imprimé.
 */
class DepartmentSlip extends Model
{
    use BelongsToCompany;

    public const KINDS = ['pickup', 'delivery'];

    protected $fillable = [
        'company_id', 'merchant_id', 'department_id', 'kind', 'day', 'order_ids',
        'signed_by_name', 'signed_by_role', 'signature_path', 'signed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['day' => 'date:Y-m-d', 'order_ids' => 'array', 'signed_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
