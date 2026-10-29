<?php

namespace Tests\Feature\YardRunner;

use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\CycleCount;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\StockLevel;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Notifications\PurchaseOrderMessage;
use App\Purchasing\CycleCounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Purchase orders and receiving, colour runs, transfers and cycle counts.
 */
class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private Location $yard;

    private Location $other;

    private Product $pavers;

    private Product $mortar;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardrunner.tax.driver' => 'flat', 'yardrunner.tax.flat_rate' => 0.13, 'yardrunner.payments.driver' => 'fake']);

        $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['address_line1' => '100 Dundas St E', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L9H 7L6']);
        $this->other = Location::factory()->create(['name' => 'Stoney Creek yard']);

        $this->supplier = Supplier::query()->create(['name' => 'Ridgeline Concrete', 'email' => 'orders@ridgeline.example', 'lead_days' => 7]);
        $this->pavers = Product::factory()->create(['name' => 'Paver 60 mm', 'unit' => Unit::SquareFoot, 'price_cents' => 545, 'cost_cents' => 300, 'track_lots' => true, 'supplier_id' => $this->supplier->id]);
        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_695, 'cost_cents' => 1_000]);

        app(InventoryLedger::class)->record($this->mortar, $this->yard, 10, StockMovementType::Receipt);
        StockLevel::query()->where('product_id', $this->mortar->id)->update(['reorder_point' => 40, 'bin' => 'Aisle A']);
    }

    public function test_purchase_orders_are_sent_and_received_into_runs(): void
    {
        Notification::fake();

        $this->get(route('purchasing.index'))->assertOk()->assertInertia(fn ($page) => $page->where('suggestions.0.product_id', $this->mortar->id));

        $this->post(route('purchasing.store'), ['supplier_id' => $this->supplier->id, 'location_id' => $this->yard->id, 'product_ids' => [$this->mortar->id]])->assertRedirect();
        $po = PurchaseOrder::query()->firstOrFail();
        $this->assertStringStartsWith('PO-', $po->number);
        $this->assertSame('70.000', $po->lines()->value('quantity'));

        $mortarLine = $po->lines()->firstOrFail();
        $this->put(route('purchasing.update', $po), [
            'location_id' => $this->yard->id,
            'lines' => [
                ['id' => $mortarLine->id, 'product_id' => $this->mortar->id, 'quantity' => 70, 'unit_cost' => '10.00'],
                ['product_id' => $this->pavers->id, 'quantity' => 1150, 'unit_cost' => '3.00'],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame(70 * 1_000 + 1150 * 300, $po->refresh()->total_cents);

        $this->post(route('purchasing.send', $po))->assertSessionHasNoErrors();
        $this->assertSame('sent', $po->refresh()->status);
        Notification::assertSentOnDemand(PurchaseOrderMessage::class);

        $paverLine = $po->lines()->where('product_id', $this->pavers->id)->firstOrFail();

        // Colour-run products need the run.
        $this->post(route('purchasing.receive', $po), ['lines' => [['line_id' => $paverLine->id, 'quantity' => 575]]])->assertSessionHasErrors('lines');

        // Part of it, at a higher cost.
        $this->post(route('purchasing.receive', $po), ['lines' => [
            ['line_id' => $paverLine->id, 'quantity' => 575, 'lot' => 'Run 2611-B', 'unit_cost' => '3.15'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('partial', $po->refresh()->status);
        $this->assertSame('575.000', StockLot::query()->where('code', 'Run 2611-B')->value('on_hand'));
        $this->pavers->refresh();
        $this->assertSame(315, $this->pavers->cost_cents);
        $this->assertSame(300, $this->pavers->previous_cost_cents);
        $this->assertNotNull($this->pavers->cost_changed_at);

        $this->post(route('purchasing.receive', $po), ['lines' => [
            ['line_id' => $mortarLine->id, 'quantity' => 70],
            ['line_id' => $paverLine->id, 'quantity' => 575, 'lot' => 'Run 2612-A'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('received', $po->refresh()->status);
        $this->assertSame('80.000', app(InventoryLedger::class)->onHand($this->mortar, $this->yard));
        $this->assertSame('1150.000', app(InventoryLedger::class)->onHand($this->pavers, $this->yard));

        $this->get(route('purchasing.show', $po))->assertOk();
    }

    public function test_orders_ship_from_one_colour_run(): void
    {
        $ledger = app(InventoryLedger::class);
        $old = StockLot::query()->create(['product_id' => $this->pavers->id, 'location_id' => $this->yard->id, 'code' => 'Run 2609-A']);
        $new = StockLot::query()->create(['product_id' => $this->pavers->id, 'location_id' => $this->yard->id, 'code' => 'Run 2611-B']);
        $ledger->record($this->pavers, $this->yard, 100, StockMovementType::Receipt, lot: $old);
        $ledger->record($this->pavers, $this->yard, 900, StockMovementType::Receipt, lot: $new);

        $org = Organization::factory()->withCredit(10_000_000)->create();
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => $org->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->pavers->id, 'quantity' => 300]);
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        $this->post(route('orders.pick', $order));
        $shipment = Shipment::query()->firstOrFail();
        $this->get(route('shipments.pick', $shipment))->assertOk()->assertInertia(fn ($page) => $page->where('items.0.where', 'Run 2611-B only'));

        $line = $shipment->lines()->firstOrFail();
        $this->post(route('shipments.lines.record', [$shipment, $line]), ['picked_quantity' => 300])->assertSessionHasNoErrors();
        $this->post(route('shipments.load', $shipment))->assertSessionHasNoErrors();

        $this->assertSame($new->id, $line->refresh()->stock_lot_id);
        $this->assertSame('600.000', $new->refresh()->on_hand);
        $this->assertSame('100.000', $old->refresh()->on_hand);
    }

    public function test_stock_moves_between_yards(): void
    {
        $this->post(route('stock-transfers.store'), ['from_location_id' => $this->yard->id, 'to_location_id' => $this->yard->id, 'lines' => [['product_id' => $this->mortar->id, 'quantity' => 1]]])->assertSessionHasErrors('to_location_id');
        $this->post(route('stock-transfers.store'), ['from_location_id' => $this->yard->id, 'to_location_id' => $this->other->id, 'lines' => [['product_id' => $this->mortar->id, 'quantity' => 11]]])->assertSessionHasErrors('lines');

        $this->post(route('stock-transfers.store'), ['from_location_id' => $this->yard->id, 'to_location_id' => $this->other->id, 'lines' => [['product_id' => $this->mortar->id, 'quantity' => 4]]])->assertSessionHasNoErrors();

        $ledger = app(InventoryLedger::class);
        $this->assertSame('6.000', $ledger->onHand($this->mortar, $this->yard));
        $this->assertSame('4.000', $ledger->onHand($this->mortar, $this->other));
    }

    public function test_cycle_counts_post_the_differences(): void
    {
        $this->post(route('cycle-counts.store'), ['location_id' => $this->yard->id, 'bin' => 'Aisle'])->assertRedirect();
        $count = CycleCount::query()->firstOrFail();
        $line = $count->lines()->firstOrFail();
        $this->assertSame('10.000', $line->expected);

        $this->get(route('cycle-counts.show', $count))->assertOk();
        $this->put(route('cycle-counts.update', $count), ['counts' => [$line->id => 9]])->assertSessionHasNoErrors();
        $this->post(route('cycle-counts.complete', $count))->assertRedirect(route('inventory.index'));

        $this->assertSame('9.000', app(InventoryLedger::class)->onHand($this->mortar, $this->yard));
        $this->assertSame(-10.0, CycleCounting::varianceSince(now()->subDay()->toImmutable()));

        $this->get(route('inventory.index'))->assertOk();
        $this->get(route('inventory.index', ['location_id' => 'all', 'low' => 1, 'category' => 'Aggregates']))->assertOk();
    }
}
