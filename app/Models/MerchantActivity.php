<?php

namespace App\Models;

use App\Enums\Activity;
use Illuminate\Database\Eloquent\Model;

/**
 * Activité d'un client (e-commerce, distribution, MAD) : un client en a une ou plusieurs.
 */
class MerchantActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['merchant_id', 'activity'];

    protected function casts(): array
    {
        return ['activity' => Activity::class];
    }
}
