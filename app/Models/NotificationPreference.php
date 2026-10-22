<?php

namespace App\Models;

use App\Enums\NotificationEvent;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'event', 'channels'];

    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'channels' => 'array',
        ];
    }
}
