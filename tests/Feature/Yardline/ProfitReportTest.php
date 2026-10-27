<?php

namespace Tests\Feature\YardRunner;

use App\Enums\PaymentTerms;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Models\StockLevel;
use App\Models\User;
use App\Purchasing\TransferStock;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's profit and loss: costs fixed when things happen, the
 * statement adding up from them, who can see it, and exports.
 */
class ProfitReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Location $yard;

    private Product $mortar;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->owner = $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'category' => 'Mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000, 'cost_cents' => 600]);
        app(InventoryLedger::class)->record($this->mortar, $this->yard, 100, StockMovementType::Receipt);

        $this->org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create();
    }

    /**
     * @param  array<int, string|int>  $lines  product id => quantity
     */
    private function order(array $lines): Order
    {
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => $this->org->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();

        foreach ($lines as $productId => $quantity) {
            $this->post(route('orders.lines.store', $order), ['product_id' => $productId, 'quantity' => $quantity])->assertSessionHasNoErrors();
        }

        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    /**
     * A week either side of today, so the test doesn't depend on the date.
     *
     * @return array<string, string>
     */
    private function around(): array
    {
        $today = now(CurrentTenant::get()->timezone ?? 'America/Toronto');

        return ['period' => 'custom', 'from' => $today->subWeek()->toDateString(), 'to' => $today->addWeek()->toDateString(), 'compare' => 'none'];
    }

    private function average(Product $product, Location $location): ?string
    {
        return StockLevel::query()->where('product_id', $product->id)->where('location_id', $location->id)->value('average_cost_cents');
    }

    public function test_receipts_blend_into_the_average_cost_and_transfers_carry_it(): void
    {
        $ledger = app(InventoryLedger::class);
        $this->assertSame('600.0000', $this->average($this->mortar, $this->yard));

        // 100 bags at $6 plus 100 at $8 average $7.
        $ledger->record($this->mortar, $this->yard, 100, StockMovementType::Receipt, unitCostCents: 800);
        $this->assertSame('700.0000', $this->average($this->mortar, $this->yard));

        // Selling doesn't change the average; it moves at it.
        $sale = $ledger->record($this->mortar, $this->yard, -50, StockMovementType::Sale);
        $this->assertSame('700.0000', $sale->unit_cost_cents);
        $this->assertSame('700.0000', $this->average($this->mortar, $this->yard));

        $other = Location::factory()->create(['name' => 'Stoney Creek']);
        app(TransferStock::class)->handle($this->yard, $other, [['product_id' => $this->mortar->id, 'quantity' => 20]], $this->owner);

        $this->assertSame('700.0000', $this->average($this->mortar, $other));
    }

    public function test_confirming_fixes_each_lines_cost_and_list_price(): void
    {
        $order = $this->order([$this->mortar->id => 10]);
        $line = $order->lines()->firstOrFail();

        $this->assertSame('600.0000', $line->unit_cost_cents);
        $this->assertSame('average', $line->cost_source);
        $this->assertSame(1_000, $line->list_price_cents);
        $this->assertSame(6_000, $line->costCents());

        // A dearer delivery later doesn't rewrite what this sale cost.
        app(InventoryLedger::class)->record($this->mortar, $this->yard, 100, StockMovementType::Receipt, unitCostCents: 900);
        $this->assertSame('600.0000', $line->refresh()->unit_cost_cents);
    }

    public function test_the_statement_adds_up_to_gross_profit(): void
    {
        // $100 of mortar that cost $60.
        $order = $this->order([$this->mortar->id => 10]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $line = $order->lines()->firstOrFail();

        // 2 bags come back on the shelf, less a 15% restocking fee.
        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 2, 'restock' => true]],
            'restocking_percent' => 15,
        ])->assertSessionHasNoErrors();

        // 5 bags broken in the yard, at the $6 average.
        $this->post(route('inventory.adjustments.store'), ['product_id' => $this->mortar->id, 'location_id' => $this->yard->id, 'mode' => 'damage', 'quantity' => 5])->assertSessionHasNoErrors();

        // A card payment the processor kept $2.50 of.
        Payment::query()->create(['order_id' => $order->id, 'tender' => 'stripe_checkout', 'status' => 'succeeded', 'amount_cents' => 2_000, 'succeeded_at' => now()])
            ->forceFill(['fee_cents' => 250])->save();

        // A compactor out on rent, and $7 of repairs on it.
        $unit = RentalUnit::factory()->create();
        Rental::factory()->create(['status' => 'out', 'checked_out_at' => now()])
            ->forceFill(['rental_cents' => 5_000, 'waiver_cents' => 500, 'delivery_fee_cents' => 1_000])->save();
        MaintenanceRecord::query()->create(['rental_unit_id' => $unit->id, 'kind' => 'repair', 'status' => 'done', 'description' => 'New belt', 'cost_cents' => 700, 'opened_at' => now(), 'completed_at' => now()]);

        // A cancelled order counts for nothing.
        $cancelled = $this->order([$this->mortar->id => 3]);
        $this->post(route('orders.cancel', $cancelled))->assertSessionHasNoErrors();

        $this->get(route('reports.profit', $this->around()))->assertOk()->assertInertia(fn ($page) => $page
            ->component('reports/profit')
            ->where('summary.lines.goods_sales', 10_000)
            ->where('summary.lines.returns', -2_000)
            ->where('summary.lines.restocking_fees', 300)
            ->where('summary.lines.rental_charges', 5_000)
            ->where('summary.lines.damage_waiver', 500)
            ->where('summary.lines.rental_delivery', 1_000)
            ->where('summary.lines.cogs', 6_000)
            ->where('summary.lines.returned_to_stock', -1_200)
            ->where('summary.lines.adjustments', 3_000)
            ->where('summary.lines.maintenance', 700)
            ->where('summary.lines.card_fees', 250)
            ->where('summary.net_revenue', 14_800)
            ->where('summary.direct_costs', 8_750)
            ->where('summary.gross_profit', 6_050)
            ->where('summary.goods.profit', 250)
            ->where('summary.rentals.profit', 5_800)
            ->where('categories.0.category', 'Mortar')
            ->where('categories.0.profit', 4_000)
            ->where('customers.0.organization_id', $this->org->id));

        // Goods only, from the counter: no rentals, no orders entered by staff.
        $this->get(route('reports.profit', [...$this->around(), 'channel' => 'counter']))->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.net_revenue', 0)
            ->where('includes.rentals', false));

        $this->getJson(route('reports.profit.lines', [...$this->around(), 'line' => 'goods_sales']))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.amount', 10_000);
    }

    public function test_lines_without_a_cost_are_flagged(): void
    {
        $sand = Product::factory()->create(['name' => 'Polymeric sand', 'unit' => Unit::Bag, 'price_cents' => 3_000]);
        app(InventoryLedger::class)->record($sand, $this->yard, 50, StockMovementType::Receipt);

        $this->order([$sand->id => 2]);

        $this->assertSame('missing', OrderLine::query()->where('product_id', $sand->id)->value('cost_source'));
        $this->get(route('reports.profit', $this->around()))->assertOk()->assertInertia(fn ($page) => $page
            ->where('memo.missing_lines', 1)
            ->where('memo.missing_revenue', 6_000));
        $this->getJson(route('reports.profit.lines', [...$this->around(), 'line' => 'missing']))
            ->assertOk()
            ->assertJsonPath('rows.0.amount', 6_000);
    }

    public function test_only_the_owner_sees_it_unless_admins_are_allowed(): void
    {
        $admin = User::factory()->create(['tenant_id' => CurrentTenant::id(), 'role' => UserRole::Admin]);
        $staff = User::factory()->create(['tenant_id' => CurrentTenant::id(), 'role' => UserRole::Sales]);

        $this->actingAs($admin)->get(route('reports.profit'))->assertForbidden();
        $this->actingAs($staff)->get(route('reports.profit'))->assertForbidden();

        $this->actingAs($this->owner)->get(route('reports.profit'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.can.viewProfit', true));
        $this->put(route('users.reports'), ['admins_can_view_profit' => true])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('reports.profit'))->assertOk();
        $this->actingAs($admin)->put(route('users.reports'), ['admins_can_view_profit' => false])->assertForbidden();
        $this->actingAs($staff)->get(route('reports.profit.csv'))->assertForbidden();
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->order([$this->mortar->id => 4]);

        $csv = $this->get(route('reports.profit.csv', [...$this->around(), 'view' => 'categories']));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Mortar', $csv->streamedContent());

        $this->get(route('reports.profit.pdf', $this->around()))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(2, AuditEvent::query()->where('event', 'exported')->count());
    }

    public function test_card_fees_are_recorded_on_card_payments(): void
    {
        $order = $this->order([$this->mortar->id => 10]);
        $card = Payment::query()->create(['order_id' => $order->id, 'tender' => 'stripe_checkout', 'status' => 'succeeded', 'amount_cents' => 10_000, 'succeeded_at' => now()]);
        $cash = Payment::query()->create(['order_id' => $order->id, 'tender' => 'cash', 'status' => 'succeeded', 'amount_cents' => 1_300, 'succeeded_at' => now()]);

        $this->artisan('yardline:payments:fees')->assertSuccessful();

        // Estimated without a card processor: 2.9% + 30¢.
        $this->assertSame(320, $card->refresh()->fee_cents);
        $this->assertNull($cash->refresh()->fee_cents);
    }
}
