<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'ouverture de compte d'un e-commerçant (inscriptions fermées) : validée ou refusée par l'agence.
 */
class ConnectRequest extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    protected $fillable = ['company_id', 'client_id', 'business_name', 'contact_name', 'phone', 'email', 'pickup_zone_id', 'pickup_address', 'message', 'password_hash', 'status', 'merchant_id', 'decided_by', 'decided_at'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function pickupZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'pickup_zone_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
