<?php

namespace Tests\Feature\YardRunner;

use App\Models\Organization;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Pricing\PriceResolver;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CurrentTenant::set(Tenant::factory()->create());
    }

    public function test_walk_ins_pay_list_price(): void
    {
        $product = Product::factory()->create(['price_cents' => 4200]);

        $this->assertSame(4200, app(PriceResolver::class)->unitPrice($product, null, 1)->cents);
    }

    public function test_price_list_overrides_list_price_with_quantity_breaks(): void
    {
        $product = Product::factory()->create(['price_cents' => 4200]);
        $list = PriceList::factory()->create();
        $list->items()->create(['product_id' => $product->id, 'min_quantity' => 1, 'price_cents' => 3900]);
        $list->items()->create(['product_id' => $product->id, 'min_quantity' => 20, 'price_cents' => 3500]);
        $org = Organization::factory()->create(['price_list_id' => $list->id]);

        $prices = app(PriceResolver::class);

        $this->assertSame(3900, $prices->unitPrice($product, $org, 5)->cents);
        $this->assertSame(3500, $prices->unitPrice($product, $org, 20)->cents);
        $this->assertSame(3500, $prices->unitPrice($product, $org, '22.500')->cents);
    }

    public function test_organization_without_a_price_list_pays_list_price(): void
    {
        $product = Product::factory()->create(['price_cents' => 1295]);
        $org = Organization::factory()->create();

        $this->assertSame(1295, app(PriceResolver::class)->unitPrice($product, $org, 10)->cents);
    }

    public function test_extending_fractional_quantities_rounds_to_the_cent(): void
    {
        $this->assertSame(9450, Money::extend('2.250', 4200));
        $this->assertSame(1, Money::extend('0.001', 545)); // 0.545 cents rounds up
        $this->assertSame(348800, Money::extend('640', 545));
    }
}
