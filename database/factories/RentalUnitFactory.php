<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Product;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RentalUnit>
 */
class RentalUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => CurrentTenant::id() ?? Tenant::factory(),
            'product_id' => fn () => Product::factory()->rental()->create()->id,
            'location_id' => fn () => Location::query()->value('id') ?? Location::factory()->create()->id,
            'serial' => fake()->unique()->bothify('??-##'),
            'status' => 'available',
            'hour_meter' => 100,
        ];
    }
}
