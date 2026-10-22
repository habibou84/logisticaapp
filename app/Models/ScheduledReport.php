<?php

namespace App\Models;

use App\Enums\ReportFrequency;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Point d'activité envoyé automatiquement au marchand sur WhatsApp.
 */
class ScheduledReport extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'frequency', 'send_time', 'weekday', 'is_active', 'last_sent_at'];

    protected function casts(): array
    {
        return [
            'frequency' => ReportFrequency::class,
            'weekday' => 'integer',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
