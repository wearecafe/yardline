<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => CurrentTenant::id() ?? Tenant::factory(),
            'name' => fake()->city().' yard',
            'code' => fake()->unique()->bothify('Y###'),
            'address_line1' => fake()->streetAddress(),
            'city' => 'Hamilton',
            'region' => 'ON',
            'postal_code' => 'L8P 4R5',
            'country' => 'CA',
            'is_default' => false,
        ];
    }
}
