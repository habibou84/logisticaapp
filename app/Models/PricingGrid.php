<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PricingGridFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingGrid extends Model
{
    /** @use HasFactory<PricingGridFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'name', 'is_default', 'base_price', 'tiers'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'base_price' => 'integer', 'tiers' => 'array'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function surcharges(): HasMany
    {
        return $this->hasMany(PricingSurcharge::class);
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }
}
