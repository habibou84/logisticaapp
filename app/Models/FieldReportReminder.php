<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Relances envoyées pour une remontée terrain restée sans suite.
 */
class FieldReportReminder extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'order_event_id', 'count', 'last_reminded_at'];

    protected function casts(): array
    {
        return ['count' => 'integer', 'last_reminded_at' => 'datetime'];
    }
}
