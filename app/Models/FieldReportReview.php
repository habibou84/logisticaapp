<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remontée terrain traitée par le dispatch (avec un commentaire facultatif).
 */
class FieldReportReview extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'order_event_id', 'handled_by', 'comment', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderEvent::class, 'order_event_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
