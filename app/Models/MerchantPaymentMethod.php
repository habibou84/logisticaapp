<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Moyen par lequel le marchand veut être payé (mobile money ou banque), avec son lien de paiement
 * et son QR code. Tant que l'agence ne l'a pas vérifié, la caisse est avertie avant de payer.
 */
class MerchantPaymentMethod extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    /** Moyens possibles : ceux de la caisse, sans les espèces */
    public const TYPES = [PaymentMethod::Wave, PaymentMethod::OrangeMoney, PaymentMethod::MtnMomo, PaymentMethod::MoovMoney, PaymentMethod::Bank];

    protected $fillable = [
        'company_id', 'merchant_id', 'type', 'number', 'holder_name', 'bank_name', 'payment_link',
        'qr_path', 'qr_mime', 'is_preferred', 'status', 'verified_by', 'verified_at', 'rejected_reason',
    ];

    protected function casts(): array
    {
        return ['type' => PaymentMethod::class, 'is_preferred' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /** Numéro lisible : 07 09 98 08 85 pour un mobile, tel quel pour un RIB */
    public function displayNumber(): string
    {
        return $this->type === PaymentMethod::Bank ? $this->number : PhoneNumber::display($this->number);
    }

    /** « Wave 07 09 98 08 85 », « Virement bancaire SGCI … » */
    public function summary(): string
    {
        return trim($this->type->label().' '.($this->bank_name ? $this->bank_name.' ' : '').$this->displayNumber());
    }
}
