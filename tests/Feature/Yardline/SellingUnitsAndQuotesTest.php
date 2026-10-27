<?php

namespace Tests\Feature\YardRunner;

use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\ContractPrice;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Pricing\PriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selling units and conversions, unit-aware and dated pricing, contract
 * prices, quotes, job sites and contact roles. Flat 13% tax.
 */
class SellingUnitsAndQuotesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $yard;

    private Product $screenings;

    private ProductUnit $yardUnit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->user = $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['address_line1' => '100 Dundas St E', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L9H 7L6']);

        $this->screenings = Product::factory()->create(['name' => 'Limestone screenings', 'unit' => Unit::Tonne, 'price_cents' => 5_800, 'cost_cents' => 3_000]);
        $this->yardUnit = $this->screenings->units()->create(['name' => 'Cubic yard', 'factor' => 1.35, 'price_cents' => 7_600, 'increment' => 0.5]);
    }

    private function draft(?Organization $org = null, array $attributes = []): Order
    {
        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $org?->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'pickup',
            ...$attributes,
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_every_product_has_its_base_unit(): void
    {
        $base = $this->screenings->baseUnit;

        $this->assertNotNull($base);
        $this->assertSame('Tonne', $base->name);
        $this->assertSame(5_800, $base->price_cents);

        $this->screenings->update(['price_cents' => 6_000]);
        $this->assertSame(6_000, $base->refresh()->price_cents);
    }

    public function test_sales_in_other_units_convert_back_to_the_base_unit(): void
    {
        app(InventoryLedger::class)->record($this->screenings, $this->yard, 100, StockMovementType::Receipt);
        $order = $this->draft();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->screenings->id, 'product_unit_id' => $this->yardUnit->id, 'quantity' => '1.25'])
            ->assertSessionHasErrors('quantity');

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->screenings->id, 'product_unit_id' => $this->yardUnit->id, 'quantity' => 10])
            ->assertSessionHasNoErrors();

        $line = $order->lines()->firstOrFail();
        $this->assertSame('Cubic yard', $line->unit);
        $this->assertSame('13.500', $line->base_quantity);
        $this->assertSame(7_600, $line->unit_price_cents);
        $this->assertSame(76_000, $line->line_total_cents);

        $this->post(route('orders.confirm', $order), ['settlement' => 'prepaid'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->post(route('orders.payments.store', $order), ['tender' => 'cash', 'amount' => $order->amountDueCents() / 100]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();

        $this->assertSame('86.500', app(InventoryLedger::class)->onHand($this->screenings, $this->yard));
    }

    public function test_contract_beats_price_list_beats_retail_with_dates(): void
    {
        $list = PriceList::query()->create(['name' => 'Contractor']);
        $list->items()->create(['product_id' => $this->screenings->id, 'min_quantity' => 1, 'price_cents' => 4_900]);
        $org = Organization::factory()->create(['price_list_id' => $list->id]);
        $prices = app(PriceResolver::class);
        $today = CarbonImmutable::today();

        // Base-unit list price, converted for cubic yards.
        $this->assertSame(4_900, $prices->unitPrice($this->screenings, $org, 1)->cents);
        $this->assertSame(6_615, $prices->unitPrice($this->screenings, $org, 1, $this->yardUnit)->cents);

        // A price for the unit itself wins over the conversion.
        $list->items()->create(['product_id' => $this->screenings->id, 'product_unit_id' => $this->yardUnit->id, 'min_quantity' => 1, 'price_cents' => 6_400]);
        $this->assertSame(6_400, $prices->unitPrice($this->screenings, $org, 1, $this->yardUnit)->cents);

        // A promotion only counts while it runs.
        $list->items()->create(['product_id' => $this->screenings->id, 'min_quantity' => 1, 'price_cents' => 4_500, 'starts_on' => $today->addDays(5), 'ends_on' => $today->addDays(10)]);
        $this->assertSame(4_900, $prices->unitPrice($this->screenings, $org, 1)->cents);
        $this->assertSame(4_500, $prices->unitPrice($this->screenings, $org, 1, null, $today->addDays(6))->cents);

        // A contract price beats them all.
        ContractPrice::query()->create(['organization_id' => $org->id, 'product_id' => $this->screenings->id, 'price_cents' => 3_900]);
        $resolved = $prices->unitPrice($this->screenings, $org, 1);
        $this->assertSame(3_900, $resolved->cents);
        $this->assertSame('contract', $resolved->source);
        $this->assertSame(5_265, $prices->unitPrice($this->screenings, $org, 1, $this->yardUnit)->cents);

        // Walk-ins pay retail.
        $this->assertSame(7_600, $prices->unitPrice($this->screenings, null, 1, $this->yardUnit)->cents);
    }

    public function test_units_and_price_tiers_save_from_the_product_editor(): void
    {
        $list = PriceList::query()->create(['name' => 'Contractor']);
        $base = $this->screenings->baseUnit;
        $this->assertNotNull($base);

        $this->put(route('products.units.sync', $this->screenings), [
            'units' => [
                ['id' => $base->id, 'name' => 'Tonne', 'factor' => 1, 'price' => '58.00', 'tiers' => [$list->id => '49.00']],
                ['id' => $this->yardUnit->id, 'name' => 'Cubic yard', 'factor' => '1.35', 'price' => '76.00', 'measured_by' => 'bucket', 'tiers' => [$list->id => '64.00']],
                ['name' => 'Bulk bag', 'factor' => '1', 'price' => '89.00', 'deposit' => '25.00', 'tiers' => [$list->id => '']],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $this->screenings->units()->count());
        $this->assertSame(2_500, $this->screenings->units()->where('name', 'Bulk bag')->value('deposit_cents'));
        $this->assertSame(4_900, $list->items()->whereNull('product_unit_id')->value('price_cents'));
        $this->assertSame(6_400, $list->items()->where('product_unit_id', $this->yardUnit->id)->value('price_cents'));

        // The base unit can't be dropped.
        $this->put(route('products.units.sync', $this->screenings), [
            'units' => [['id' => $this->yardUnit->id, 'name' => 'Cubic yard', 'factor' => '1.35', 'price' => '76.00']],
        ])->assertSessionHasErrors();

        $this->get(route('products.edit', $this->screenings))->assertOk();
    }

    public function test_quotes_hold_their_prices_until_they_expire(): void
    {
        $customer = Customer::factory()->create(['email' => 'pat@example.com']);
        $order = $this->draft(null, ['customer_id' => $customer->id]);
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->screenings->id, 'quantity' => 10]);

        $this->post(route('orders.quote', $order), ['expires_on' => now()->addDays(14)->toDateString(), 'email' => false])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertTrue($order->isQuote());
        $this->assertSame(1, $order->revision);
        $this->assertNotNull($order->quote_token);
        $this->assertSame(65_540, $order->total_cents);

        // A price rise doesn't touch the quote...
        $this->screenings->update(['price_cents' => 6_500]);
        $this->patch(route('orders.lines.update', [$order, $order->lines()->firstOrFail()]), ['quantity' => 12])->assertSessionHasNoErrors();
        $this->assertSame(5_800, $order->lines()->value('unit_price_cents'));

        // ...and resending makes a revision.
        $this->post(route('orders.quote', $order), ['expires_on' => now()->addDays(14)->toDateString(), 'email' => false]);
        $this->assertSame(2, $order->refresh()->revision);
        $this->assertSame(2, $order->revisions()->count());

        // The customer sees it and accepts.
        auth()->logout();
        $this->get(route('quotes.public', $order->quote_token))->assertOk();
        $this->post(route('quotes.accept', $order->quote_token), ['name' => 'Pat Walker', 'po_number' => 'PO-77'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('Pat Walker', $order->quote_accepted_by);
        $this->assertSame('PO-77', $order->po_number);

        // After expiry, prices move to today's.
        $this->actingAs($this->user);
        $this->travel(15)->days();
        $this->patch(route('orders.lines.update', [$order, $order->lines()->firstOrFail()]), ['quantity' => 12]);
        $this->assertSame(6_500, $order->lines()->value('unit_price_cents'));
        $this->assertTrue($order->refresh()->quoteExpired());
    }

    public function test_job_sites_fill_the_address_and_can_require_a_po(): void
    {
        $org = Organization::factory()->withCredit(1_000_000)->create();
        $this->post(route('organizations.locations.store', $org), [
            'name' => 'Phase 2',
            'address_line1' => 'Lot 14, Kerncliff Estates',
            'city' => 'Burlington',
            'region' => 'ON',
            'postal_code' => 'L7P 0A1',
            'po_required' => true,
        ])->assertSessionHasNoErrors();
        $site = OrganizationLocation::query()->firstOrFail();

        $order = $this->draft($org, ['organization_location_id' => $site->id, 'fulfillment' => 'delivery', 'delivery_address' => ['line1' => '', 'postal_code' => 'L7P 0A1']]);
        $this->assertSame($site->id, $order->organization_location_id);
        $this->assertSame('Lot 14, Kerncliff Estates', $order->delivery_address['line1'] ?? null);

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->screenings->id, 'quantity' => 2]);
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasErrors('order');

        $order->update(['po_number' => 'KH-1']);
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();
    }

    public function test_contacts_without_charge_rights_cannot_put_orders_on_account(): void
    {
        $org = Organization::factory()->withCredit(1_000_000)->create();
        $other = Organization::factory()->create();
        $jen = Customer::factory()->create(['organization_id' => $other->id]);

        $this->post(route('organizations.contacts.store', $org), ['customer_id' => $jen->id, 'role' => 'buyer', 'can_charge_account' => false])->assertSessionHasNoErrors();
        $this->assertSame(2, $jen->organizations()->count());

        $order = $this->draft($org, ['customer_id' => $jen->id]);
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->screenings->id, 'quantity' => 2]);
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasErrors('order');

        $this->patch(route('organizations.contacts.update', [$org, $jen]), ['role' => 'approver', 'can_charge_account' => true])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();
    }

    public function test_account_pages_render(): void
    {
        $org = Organization::factory()->create();
        ContractPrice::query()->create(['organization_id' => $org->id, 'product_id' => $this->screenings->id, 'product_unit_id' => $this->yardUnit->id, 'price_cents' => 6_000]);
        $list = PriceList::query()->create(['name' => 'Contractor']);
        $customer = Customer::factory()->create(['organization_id' => $org->id]);

        $this->get(route('organizations.show', $org))->assertOk();
        $this->get(route('price-lists.edit', $list))->assertOk();
        $this->get(route('customers.edit', $customer))->assertOk();
        $order = $this->draft($org);
        $this->get(route('orders.show', $order))->assertOk();
    }
}
