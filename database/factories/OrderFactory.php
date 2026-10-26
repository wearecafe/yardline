<?php

namespace Database\Factories;

use App\Enums\OrderType;
use App\Models\Location;
use App\Models\Order;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => CurrentTenant::id() ?? Tenant::factory(),
            'number' => 'SO-'.fake()->unique()->numberBetween(10000, 99999),
            'type' => OrderType::Sale,
            'location_id' => Location::factory(),
        ];
    }

    public function rental(): static
    {
        return $this->state(fn () => [
            'type' => OrderType::Rental,
            'number' => 'RN-'.fake()->unique()->numberBetween(10000, 99999),
            'rental_starts_at' => now()->addDay(),
            'rental_ends_at' => now()->addDays(3),
        ]);
    }
}
