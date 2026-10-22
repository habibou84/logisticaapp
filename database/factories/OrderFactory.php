<?php

namespace Database\Factories;

use App\Enums\FeePayer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pour des courses réalistes (prix calculé, journal), passer par OrderService.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'company_id' => fn (array $a) => Merchant::withoutGlobalScopes()->find($a['merchant_id'])->company_id,
            'pickup_zone_id' => fn (array $a) => Zone::factory()->create(['company_id' => $a['company_id']])->id,
            'delivery_zone_id' => fn (array $a) => Zone::factory()->create(['company_id' => $a['company_id']])->id,
            'recipient_name' => fake()->name(),
            'recipient_phone' => '+22507'.fake()->numerify('########'),
            'delivery_address' => fake()->streetAddress(),
            'delivery_fee' => 1500,
            'fee_payer' => FeePayer::Merchant,
            'items_amount' => 10000,
            'cod_amount' => 10000,
        ];
    }
}
