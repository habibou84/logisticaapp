<?php

namespace Database\Factories;

use App\Enums\FeePayer;
use App\Enums\MerchantStatus;
use App\Models\Company;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'business_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'phone' => '+22505'.fake()->unique()->numerify('########'),
            'default_fee_payer' => FeePayer::Merchant,
            'status' => MerchantStatus::Active,
        ];
    }
}
