<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->city(),
            'city' => 'Abidjan',
            'is_active' => true,
        ];
    }
}
