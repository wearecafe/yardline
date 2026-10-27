<?php

namespace Tests\Feature\YardRunner;

use App\Enums\QuantityRounding;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Interlock sold by the layer (10.62 sq ft) or the pallet (116.82 sq ft),
 * never by the single paver: units switched off for sale, and quantities
 * rounded to the nearest mix of the units that are.
 */
class RoundingAndSellabilityTest extends TestCase
{
    use RefreshDatabase;

    private Location $yard;

    private Product $interlock;

    private ProductUnit $squareFoot;

    private ProductUnit $layer;

    private ProductUnit $pallet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();

        $this->interlock = Product::factory()->create([
            'name' => 'Holland paver, 60 mm',
            'unit' => Unit::SquareFoot,
            'price_cents' => 500,
            'pallet_deposit' => true,
            'per_pallet' => 116.82,
            'quantity_rounding' => QuantityRounding::Up,
        ]);
        $base = $this->interlock->baseUnit;
        $this->assertNotNull($base);
        $base->update(['sellable' => false]);
        $this->squareFoot = $base;
        $this->layer = $this->interlock->units()->create(['name' => 'Layer (10.62 sq ft)', 'factor' => 10.62, 'increment' => 1, 'price_cents' => 5_200]);
        $this->pallet = $this->interlock->units()->create(['name' => 'Pallet (116.82 sq ft)', 'factor' => 116.82, 'increment' => 1, 'price_cents' => 54_900]);

        app(InventoryLedger::class)->record($this->interlock, $this->yard, 2_000, StockMovementType::Receipt);
    }

    private function draft(?Organization $org = null, ?Customer $customer = null): Order
    {
        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $org?->id,
            'customer_id' => $customer?->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'pickup',
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_a_unit_switched_off_is_not_sold_on_its_own(): void
    {
        $this->interlock->update(['quantity_rounding' => QuantityRounding::Off]);
        $order = $this->draft();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->squareFoot->id, 'quantity' => 31.86])
            ->assertSessionHasErrors(['quantity' => 'Square foot isn’t sold on its own.']);

        // No unit given means the base unit, which isn't for sale either.
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'quantity' => 31.86])
            ->assertSessionHasErrors('quantity');

        // Without rounding there's nothing to offer.
        $this->getJson(route('products.fit', ['product' => $this->interlock, 'quantity' => 300, 'product_unit_id' => $this->squareFoot->id]))
            ->assertOk()
            ->assertJson(['fits' => false, 'proposal' => null]);

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->layer->id, 'quantity' => 3])
            ->assertSessionHasNoErrors();
        $this->assertSame('31.860', $order->lines()->value('base_quantity'));
    }

    public function test_staff_see_the_rounded_mix_before_it_goes_on(): void
    {
        $this->getJson(route('products.fit', ['product' => $this->interlock, 'quantity' => 300, 'product_unit_id' => $this->squareFoot->id]))
            ->assertOk()
            ->assertJson([
                'fits' => false,
                'problem' => 'Square foot isn’t sold on its own.',
                'proposal' => [
                    'direction' => 'up',
                    'summary' => '2 × Pallet (116.82 sq ft) + 7 × Layer (10.62 sq ft)',
                    'requested' => '300 sq ft',
                    'total' => '307.98 sq ft',
                    'exact' => false,
                    'lines' => [
                        ['product_unit_id' => $this->pallet->id, 'quantity' => '2'],
                        ['product_unit_id' => $this->layer->id, 'quantity' => '7'],
                    ],
                ],
            ]);

        // A quantity that fits needs no offer.
        $this->getJson(route('products.fit', ['product' => $this->interlock, 'quantity' => 3, 'product_unit_id' => $this->layer->id]))
            ->assertJson(['fits' => true, 'proposal' => null]);

        // Layers past a pallet's worth become a pallet.
        $this->getJson(route('products.fit', ['product' => $this->interlock, 'quantity' => 116.82, 'product_unit_id' => $this->squareFoot->id]))
            ->assertJson(['proposal' => ['exact' => true, 'summary' => '1 × Pallet (116.82 sq ft)']]);
    }

    public function test_an_accepted_rounding_adds_a_line_per_unit(): void
    {
        $order = $this->draft();

        // Not accepted yet: refused, so nothing is added without a look.
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->squareFoot->id, 'quantity' => 300])
            ->assertSessionHasErrors('quantity');
        $this->assertSame(0, $order->lines()->count());

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->squareFoot->id, 'quantity' => 300, 'round' => true])
            ->assertSessionHasNoErrors();

        $lines = $order->lines()->orderBy('position')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(['Pallet (116.82 sq ft)', 'Layer (10.62 sq ft)'], $lines->pluck('unit')->all());
        $this->assertSame(['2.000', '7.000'], $lines->pluck('quantity')->all());
        $this->assertSame(['233.640', '74.340'], $lines->pluck('base_quantity')->all());
        $this->assertSame([109_800, 36_400], $lines->pluck('line_total_cents')->all());
        // Two pallets, plus the one the loose layers ship on.
        $this->assertSame([2, 1], $lines->pluck('pallets')->all());

        // Rounding sets its own prices.
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->squareFoot->id, 'quantity' => 50, 'round' => true, 'unit_price' => '4.00'])
            ->assertSessionHasErrors('unit_price');
    }

    public function test_rounding_down(): void
    {
        $this->interlock->update(['quantity_rounding' => QuantityRounding::Down]);
        $order = $this->draft();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->squareFoot->id, 'quantity' => 300, 'round' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(['2.000', '6.000'], $order->lines()->orderBy('position')->pluck('quantity')->all());

        // Below one layer there's nothing to round down to.
        $this->getJson(route('products.fit', ['product' => $this->interlock, 'quantity' => 5, 'product_unit_id' => $this->squareFoot->id]))
            ->assertJson(['fits' => false, 'proposal' => null, 'problem' => '5 sq ft is less than the smallest sale, 1 × Layer (10.62 sq ft).']);
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->squareFoot->id, 'quantity' => 5, 'round' => true])
            ->assertSessionHasErrors('quantity');
    }

    public function test_the_product_editor_saves_what_is_for_sale_and_how_it_rounds(): void
    {
        $units = fn (bool $baseForSale, bool $layerForSale, bool $palletForSale) => [
            ['id' => $this->squareFoot->id, 'name' => 'Square foot', 'factor' => 1, 'price' => '5.00', 'sellable' => $baseForSale],
            ['id' => $this->layer->id, 'name' => 'Layer (10.62 sq ft)', 'factor' => '10.62', 'price' => '52.00', 'increment' => '1', 'sellable' => $layerForSale],
            ['id' => $this->pallet->id, 'name' => 'Pallet (116.82 sq ft)', 'factor' => '116.82', 'price' => '549.00', 'increment' => '1', 'min_quantity' => '1', 'sellable' => $palletForSale],
        ];

        $this->put(route('products.units.sync', $this->interlock), ['rounding' => 'down', 'units' => $units(false, true, true)])
            ->assertSessionHasNoErrors();
        $this->assertSame(QuantityRounding::Down, $this->interlock->refresh()->quantity_rounding);
        $this->assertFalse($this->squareFoot->refresh()->sellable);
        $this->assertSame('1.000', $this->pallet->refresh()->min_quantity);

        // Something has to be for sale.
        $this->put(route('products.units.sync', $this->interlock), ['units' => $units(false, false, false)])
            ->assertSessionHasErrors('units');
        $this->assertTrue($this->layer->refresh()->sellable);

        $this->get(route('products.edit', $this->interlock))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rounding', 'down')
            ->where('sellingUnits.0.sellable', false)
            ->has('roundingOptions', 3));
    }

    public function test_order_entry_and_the_counter_offer_units_for_sale(): void
    {
        $order = $this->draft();

        $this->get(route('orders.show', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.0.default_unit_id', $this->layer->id)
            ->where('products.0.rounding', 'up')
            // The base unit stays as a way to enter an area, marked not for sale.
            ->where('products.0.units.0.id', $this->squareFoot->id)
            ->where('products.0.units.0.sellable', false)
            ->has('products.0.units', 3));

        $this->get(route('counter.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.0.default_unit_id', $this->layer->id)
            ->has('products.0.units', 3));

        // Without rounding the base unit isn't offered at all.
        $this->interlock->update(['quantity_rounding' => QuantityRounding::Off]);
        $this->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->has('products.0.units', 2)
            ->where('products.0.units.0.id', $this->layer->id));
        $this->get(route('counter.show'))->assertInertia(fn (Assert $page) => $page->has('products.0.units', 2));
    }

    public function test_portal_reorders_and_the_calculator_round_too(): void
    {
        $org = Organization::factory()->withCredit(1_000_000)->create();
        $mike = Customer::factory()->create(['organization_id' => $org->id, 'first_name' => 'Mike', 'email' => 'mike@example.com']);

        // An old order sold by the square foot, before that was switched off.
        $this->squareFoot->update(['sellable' => true]);
        $order = $this->draft($org, $mike);
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->interlock->id, 'product_unit_id' => $this->squareFoot->id, 'quantity' => 300])
            ->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();
        $this->squareFoot->update(['sellable' => false]);

        $tenant = CurrentTenant::get() ?? Tenant::factory()->create();
        auth()->logout();
        $this->get(URL::temporarySignedRoute('portal.enter', now()->addMinutes(30), ['tenant' => $tenant->slug, 'customer' => $mike->id]))
            ->assertRedirect(route('portal.home'));

        $this->post(route('portal.reorder', $order))->assertRedirect();
        $draft = Order::query()->latest('id')->firstOrFail();
        $this->assertSame('portal', $draft->source);
        $this->assertSame(['Pallet (116.82 sq ft)', 'Layer (10.62 sq ft)'], $draft->lines()->orderBy('position')->pluck('unit')->all());

        // 280 sq ft + 5% waste = 294 sq ft, up to 2 pallets + 6 layers.
        $this->getJson(route('portal.calculator.pavers', ['area' => 280]))
            ->assertOk()
            ->assertJson(['lines' => [
                ['unit_id' => $this->pallet->id, 'quantity' => 2, 'price_cents' => 54_900],
                ['unit_id' => $this->layer->id, 'quantity' => 6, 'price_cents' => 5_200],
            ]]);
    }
}
