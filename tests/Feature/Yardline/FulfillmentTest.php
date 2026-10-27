<?php

namespace Tests\Feature\YardRunner;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementType;
use App\Enums\StopStatus;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\Truck;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Picking, partial shipments, loading, the dispatch board, proof of
 * delivery and delivery fees. Flat 13% tax.
 */
class FulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPAAAABGCAQAAABYz9FxAAACl0lEQVR42u2bW3LDIAxFgyfLyP5X5n2kHx6PxzWYlySEc85XmyZG1UVCCPJ6AQAAAAAAAAAAAAAAAAAAwK/x+X6+eMEzoU/e/ac14MqHCbyJuwm7C43M/lj65X291oC0jxL4LO8RvazHjxA4Ji8J+mEpOiUmMTy9wHcSEsP+eEvG7zYBkLklYLS8FurNuTcFgVtzoY7fqgXOmZGfAhArVVOlq+kaXFpCjSi1Pt+6tmnt+3Xl/d8yGhTBZenXOobrU51Ncqz3kUYUh16jRkscT3Wp8a9/1UqNbf6RtyZIx6+l01LjSL2uZ3d6LOngWOqc2b+N0pd3742f19jtt3jXXGv9a/WkpCVqMamfpnMjXN2Ue7fFxMx7UjafLDryeoiDc7SWnHjpx3CJJ82LrFZ5dWNC/uk2Oae8ypexZZkxenXEsDnwLLFasiZY9OSdr581tmaotaSsVbPoRq9ORGgmU80YXoOULYe0OZnfmql5DZwPt06czXfXHXO8VZPeWSv3U3RiTTuCdSxuLVSPz7U0at76q5H0AeKs51UtNu9xfP+MNVRG8G9vZ2qfnz+y782IPZ8f1i/2vVstP1TJR+nYjaaL5py3BF1+LHq2Jf6fjl1SFv0hpFv5dh3j2mkbP+QYu/KbntqmxC6XzO5IIHfz7M5u2wsF97aaH3T3dm/sjiL7iib525Jp77kROC1R2Tptt5rd2aNbNLXJ6Ejg9qiwrUbTo8lMM2kh3QucF3DMZb54q7Du8tKoUtGZwPnEaL3ZKN0IeRByCoH9XIuLTas5v5XlzrBYpI7rBR0j+7z44KLRIVOAjHHtvm+fVV6HAl9bIWN7QcdlvTm/ceXU6PlTIwJXpGnkfaDAY4srAAAAAAAAAAAAABDkDz4VeEPQ4wq3AAAAAElFTkSuQmCC';

    private User $user;

    private Location $yard;

    private Product $screenings;

    private Product $sand;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);
        Storage::fake(config('yardline.files_disk'));

        $this->user = $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['address_line1' => '100 Dundas St E', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L9H 7L6']);

        $this->screenings = Product::factory()->create(['name' => 'Limestone screenings', 'sku' => 'AGG-SCR', 'unit' => Unit::Tonne, 'price_cents' => 5_000, 'weight_kg' => 1000]);
        $this->screenings->baseUnit?->update(['measured_by' => 'scale']);
        $this->sand = Product::factory()->create(['name' => 'Polymeric sand', 'sku' => 'SND-POLY', 'unit' => Unit::Bag, 'price_cents' => 4_000, 'weight_kg' => 25]);

        $ledger = app(InventoryLedger::class);
        $ledger->record($this->screenings, $this->yard, 100, StockMovementType::Receipt);
        $ledger->record($this->sand, $this->yard, 100, StockMovementType::Receipt);

        $this->org = Organization::factory()->withCredit(10_000_000)->create();
        DeliveryZone::query()->create(['name' => 'Zone 1', 'postal_prefixes' => ['L8B'], 'fee_cents' => 6_500, 'position' => 1]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function confirmedOrder(array $attributes = [], array $lines = ['screenings' => 4, 'sand' => 20]): Order
    {
        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $this->org->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'pickup',
            ...$attributes,
        ])->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();

        foreach ($lines as $key => $quantity) {
            $this->post(route('orders.lines.store', $order), ['product_id' => $this->{$key}->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
        }

        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    public function test_short_picks_ship_in_parts(): void
    {
        $order = $this->confirmedOrder();

        $this->post(route('orders.pick', $order))->assertRedirect();
        $shipment = Shipment::query()->firstOrFail();
        $this->assertSame(ShipmentStatus::Picking, $shipment->status);
        $this->assertSame('picking', $order->refresh()->fulfillment_status);

        $scr = $shipment->lines()->whereHas('orderLine', fn ($q) => $q->where('sku', 'AGG-SCR'))->firstOrFail();
        $bags = $shipment->lines()->whereHas('orderLine', fn ($q) => $q->where('sku', 'SND-POLY'))->firstOrFail();

        // A scale reading within 5% is a full pick; fewer bags is short.
        $this->post(route('shipments.lines.record', [$shipment, $scr]), ['picked_quantity' => '4.12'])->assertSessionHasNoErrors();
        $this->post(route('shipments.lines.record', [$shipment, $bags]), ['picked_quantity' => 15, 'short' => true, 'note' => 'Only 15 on the shelf'])->assertSessionHasNoErrors();
        $this->assertFalse($scr->refresh()->short);
        $this->assertTrue($bags->refresh()->short);

        $this->get(route('shipments.pick', $shipment))->assertOk();
        $this->post(route('shipments.load', $shipment))->assertSessionHasNoErrors();

        $ledger = app(InventoryLedger::class);
        $this->assertSame('95.880', $ledger->onHand($this->screenings, $this->yard));
        $this->assertSame('85.000', $ledger->onHand($this->sand, $this->yard));
        $this->assertSame(ShipmentStatus::Ready, $shipment->refresh()->status);

        $order->refresh();
        $this->assertSame('partly_shipped', $order->fulfillment_status);
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        // The rest goes on a second pick list.
        $this->post(route('orders.pick', $order))->assertRedirect();
        $second = Shipment::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $second->lines()->count());
        $this->assertSame('5.000', $second->lines()->value('quantity'));
        $this->post(route('shipments.load', $second))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        $this->assertSame('80.000', $ledger->onHand($this->sand, $this->yard));

        // The counter hands it over.
        $this->post(route('shipments.complete', $second), ['name' => 'Pat'])->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::PickedUp, $second->refresh()->status);
    }

    public function test_deliveries_go_from_plan_to_proof_of_delivery(): void
    {
        $truck = Truck::query()->create(['name' => 'Truck 2 · boom', 'capacities' => [['unit' => 't', 'amount' => 12]], 'has_boom' => true]);
        // The yard's today, not UTC's: dispatch works on the yard's calendar,
        // and after 8 pm in Toronto UTC is already tomorrow.
        $today = now(CurrentTenant::get()?->timezone ?? 'America/Toronto')->toDateString();
        $customer = Customer::factory()->create(['organization_id' => $this->org->id, 'phone' => '905-555-0100']);
        $order = $this->confirmedOrder([
            'customer_id' => $customer->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '18 Ridgeview Dr', 'city' => 'Waterdown', 'postal_code' => 'L8B 1J2'],
            'requested_on' => $today,
        ]);

        // Zone fee, taxed with the goods.
        $this->assertSame(6_500, $order->delivery_fee_cents);
        $this->assertSame(20_000 + 80_000 + 6_500, $order->subtotal_cents);
        $this->assertSame(13_845, $order->tax_cents);
        $this->assertSame('4500.0', $order->load_weight_kg);

        // Planned on the board before it's loaded.
        $stop = Stop::query()->firstOrFail();
        $this->assertSame('order', $stop->stoppable_type);
        $this->patch(route('stops.schedule', $stop), ['truck_id' => $truck->id, 'date' => $today, 'starts_at' => '09:00'])->assertSessionHasNoErrors();
        $this->post(route('stops.en-route', $stop))->assertSessionHasErrors();

        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $shipment = Shipment::query()->firstOrFail();
        $this->assertSame(ShipmentStatus::Loaded, $shipment->status);
        $stop->refresh();
        $this->assertSame('shipment', $stop->stoppable_type);
        $this->assertSame(StopStatus::Scheduled, $stop->status);

        $this->post(route('stops.en-route', $stop), ['eta' => '10:40'])->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::Out, $shipment->refresh()->status);
        $this->post(route('dispatch.etas'))->assertSessionHasNoErrors();
        $this->assertNotNull($stop->refresh()->eta_sent_at);

        $this->get(route('dispatch.board'))->assertOk();
        $this->get(route('shipments.deliver', $shipment))->assertOk();

        $this->post(route('shipments.complete', $shipment), ['name' => 'Jen Okafor'])->assertSessionHasErrors('signature');
        $this->post(route('shipments.complete', $shipment), ['name' => 'Jen Okafor', 'signature' => self::SIGNATURE, 'notes' => 'By the garage'])->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertNotNull($shipment->pod_signature_path);
        $this->assertSame(StopStatus::Done, $stop->refresh()->status);
        $this->assertSame('delivered', $order->refresh()->fulfillment_status);
    }

    public function test_delivery_fee_by_truckload_or_by_hand(): void
    {
        $tenant = CurrentTenant::get();
        $tenant?->update(['settings' => ['delivery' => ['method' => 'truckload', 'per_load_cents' => 12_500, 'load_capacity_kg' => 14_000]]]);

        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $this->org->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '1 Main St', 'postal_code' => 'N0B 1A0'],
        ]);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->screenings->id, 'quantity' => 20]);

        $order->refresh();
        $this->assertSame(2, $order->delivery_loads);
        $this->assertSame(25_000, $order->delivery_fee_cents);

        $this->put(route('orders.update', $order), [
            'organization_id' => $this->org->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '1 Main St', 'postal_code' => 'N0B 1A0'],
            'delivery_fee' => '180.00',
        ])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertTrue($order->delivery_fee_manual);
        $this->assertSame(18_000, $order->delivery_fee_cents);
        $this->assertSame(100_000 + 18_000, $order->subtotal_cents);
    }

    public function test_delivered_rentals_put_runs_on_the_board(): void
    {
        $saw = Product::factory()->rental()->create(['price_cents' => 8_500]);
        $unit = RentalUnit::factory()->create(['product_id' => $saw->id]);
        $customer = Customer::factory()->create();

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'location_id' => $this->yard->id,
            'starts_at' => now()->addDay()->format('Y-m-d').'T07:00',
            'ends_at' => now()->addDays(3)->format('Y-m-d').'T17:00',
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '9 Orchard Cres', 'postal_code' => 'L8B 0C2'],
            'payment_method' => 'counter',
            'items' => [['product_id' => $saw->id, 'rental_unit_id' => $unit->id]],
        ])->assertSessionHasNoErrors();
        $rental = Rental::query()->firstOrFail();

        $this->post(route('rentals.reserve', $rental))->assertSessionHasNoErrors();
        $this->assertSame(['rental_drop', 'rental_pickup'], Stop::query()->orderBy('kind')->pluck('kind')->all());

        $this->post(route('rentals.cancel', $rental))->assertSessionHasNoErrors();
        $this->assertSame(0, Stop::query()->where('status', '!=', 'cancelled')->count());
    }

    public function test_fulfillment_pages_render(): void
    {
        $order = $this->confirmedOrder();
        $this->get(route('orders.queue'))->assertOk();
        $this->get(route('orders.queue', ['card' => "o{$order->id}"]))->assertOk();

        $this->post(route('orders.pick', $order));
        $shipment = Shipment::query()->firstOrFail();
        $this->get(route('orders.queue', ['card' => "s{$shipment->id}"]))->assertOk();
        $this->get(route('shipments.ticket', $shipment))->assertOk();
        $this->get(route('orders.show', $order))->assertOk();
        $this->get(route('delivery-settings.edit'))->assertOk();
        $this->get(route('rental-settings.edit'))->assertOk();
        $this->get(route('products.edit', $this->screenings))->assertOk();

        $this->post(route('shipments.cancel', $shipment))->assertRedirect();
        $this->assertSame(0, Shipment::query()->count());
    }
}
