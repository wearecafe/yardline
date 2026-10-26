<?php

namespace Database\Factories;

use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\Unit;
use App\Models\Product;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => CurrentTenant::id() ?? Tenant::factory(),
            'sku' => fake()->unique()->bothify('SKU-####??'),
            'name' => fake()->randomElement(['Limestone screenings', 'Triple mix', 'Paver', 'Cap stone', 'Mortar']).' '.fake()->word(),
            'category' => 'Aggregates',
            'kind' => ProductKind::Sale,
            'unit' => Unit::Each,
            'price_cents' => fake()->numberBetween(500, 50000),
            'track_inventory' => true,
            'is_active' => true,
        ];
    }

    public function rental(int $deposit = 25000): static
    {
        return $this->state(fn () => [
            'kind' => ProductKind::Rental,
            'rental_period' => RentalPeriod::Day,
            'deposit_cents' => $deposit,
            'category' => 'Equipment rental',
        ]);
    }
}
