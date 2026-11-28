<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp des alertes, relié en scannant un QR code : dernier état connu de la session.
 */
class WhatsAppQrSession extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_qr_sessions';

    protected $fillable = [
        'company_id', 'status', 'phone', 'name', 'connected_at', 'disconnected_at', 'checked_at', 'warned_at', 'last_error',
    ];

    protected $attributes = ['status' => 'disconnected'];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'checked_at' => 'datetime',
            'warned_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public static function forCompanyId(int $companyId): self
    {
        return static::forCompany($companyId)->firstOrNew(['company_id' => $companyId]);
    }
}
