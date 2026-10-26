<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Rental;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rental>
 */
class RentalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => CurrentTenant::id() ?? Tenant::factory(),
            'number' => 'R-'.fake()->unique()->numberBetween(10000, 99999),
            'location_id' => fn () => Location::query()->value('id') ?? Location::factory()->create()->id,
            'starts_at' => now()->addDay()->setTime(7, 0),
            'ends_at' => now()->addDays(2)->setTime(17, 0),
        ];
    }
}
