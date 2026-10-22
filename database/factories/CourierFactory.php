<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\VehicleType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crée un livreur complet : compte utilisateur (rôle livreur) et profil.
 *
 * @extends Factory<Courier>
 */
class CourierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => fn (array $attributes) => User::factory()->create(['company_id' => $attributes['company_id']])->id,
            'vehicle_type' => VehicleType::Moto,
            'is_available' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Courier $courier) => $courier->user->assignRole(Role::Courier->value));
    }
}
