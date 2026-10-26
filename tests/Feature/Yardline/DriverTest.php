<?php

namespace Tests\Feature\Yardline;

use App\Enums\ShipmentStatus;
use App\Enums\StockMovementType;
use App\Enums\StopStatus;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Fulfillment\DeliveryTracking;
use App\Fulfillment\DriverRun;
use App\Inventory\InventoryLedger;
use App\Models\Customer;
use App\Models\DriverPing;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\Tenant;
use App\Models\Truck;
use App\Models\User;
use App\Notifications\DriverInviteMessage;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Drivers: their own run on their phone, nothing else in the yard, proof
 * of delivery for their stops only, location sharing, and what
 * contractors see about a delivery in the portal.
 */
class DriverTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $owner;

    private Tenant $tenant;

    private Location $yard;

    private Organization $org;

    private Customer $mike;

    private Product $sand;

    private Truck $truck2;

    private Truck $truck3;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);
        Storage::fake(config('yardline.files_disk'));

        $this->owner = $this->signInToNewTenant();
        $this->tenant = CurrentTenant::get() ?? Tenant::factory()->create();
        // Keep "today" the same day for the app and for now() in the test.
        $this->tenant->update(['timezone' => 'UTC']);
        CurrentTenant::set($this->tenant->refresh());
        $this->yard = Location::query()->firstOrFail();
        $this->org = Organization::factory()->withCredit(10_000_000)->create(['name' => 'Brantwood Landscaping']);
        $this->mike = Customer::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Mike', 'phone' => '905-555-0143', 'email' => 'mike@brantwood.example']);
        $this->sand = Product::factory()->create(['name' => 'Polymeric sand', 'sku' => 'SND-POLY', 'unit' => Unit::Bag, 'price_cents' => 4_000, 'weight_kg' => 25]);
        app(InventoryLedger::class)->record($this->sand, $this->yard, 500, StockMovementType::Receipt);

        $this->driver = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Driver, 'name' => 'Sam Rinaldi']);
        $this->truck2 = Truck::query()->create(['name' => 'Truck 2 · boom', 'driver_id' => $this->driver->id, 'position' => 1]);
        $this->truck3 = Truck::query()->create(['name' => 'Truck 3', 'driver_name' => 'Luis Moreno', 'position' => 2]);
    }

    /**
     * A delivery order, loaded and on a truck today at the given time.
     */
    private function loadedStop(Truck $truck, string $time, string $line1 = '251 Hamilton St N'): Stop
    {
        $this->actingAs($this->owner);

        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $this->org->id,
            'customer_id' => $this->mike->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => $line1, 'city' => 'Waterdown', 'postal_code' => 'L8B 1B4'],
            'requested_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->sand->id, 'quantity' => 10])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        $stop = Stop::query()->where('stoppable_type', 'order')->where('stoppable_id', $order->id)->firstOrFail();
        $this->patch(route('stops.schedule', $stop), ['truck_id' => $truck->id, 'date' => now()->toDateString(), 'starts_at' => $time])->assertSessionHasNoErrors();
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();

        return $stop->refresh();
    }

    public function test_drivers_see_only_their_own_run(): void
    {
        $mine = $this->loadedStop($this->truck2, '09:00');
        $theirs = $this->loadedStop($this->truck3, '10:00', '18 Ridgeview Dr');

        $this->actingAs($this->driver);

        $this->get(route('driver.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('driver/today')
                ->where('canPickTruck', false)
                ->has('trucks', 1)
                ->has('stops', 1)
                ->where('stops.0.id', $mine->id)
                ->where('stops.0.title', 'Brantwood Landscaping')
                ->where('stops.0.loaded', true));

        $this->get(route('driver.stops.show', $mine))->assertOk()->assertInertia(fn (Assert $page) => $page->has('stop.items', 1));
        $this->get(route('driver.stops.show', $theirs))->assertNotFound();

        // The office can open any truck's run.
        $this->actingAs($this->owner);
        $this->get(route('driver.today', ['truck' => $this->truck3->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canPickTruck', true)->where('stops.0.id', $theirs->id));
    }

    public function test_drivers_are_kept_to_their_run(): void
    {
        $order = Order::query()->create(['type' => 'sale', 'number' => 'SO-9999', 'organization_id' => $this->org->id, 'location_id' => $this->yard->id]);
        $this->actingAs($this->driver);

        $this->get(route('dashboard'))->assertRedirect(route('driver.today'));
        $this->get(route('orders.index'))->assertRedirect(route('driver.today'));
        $this->get(route('receivables.index'))->assertRedirect(route('driver.today'));
        $this->get(route('dispatch.board'))->assertRedirect(route('driver.today'));
        $this->post(route('orders.cancel', $order))->assertForbidden();
        $this->assertContains($this->get(route('drivers.index'))->status(), [302, 403]);

        // Their own account settings still work.
        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_drivers_leave_for_a_stop_and_take_proof_of_delivery(): void
    {
        $stop = $this->loadedStop($this->truck2, '09:00');
        $other = $this->loadedStop($this->truck3, '09:00', '18 Ridgeview Dr');
        $shipment = Shipment::query()->whereKey($stop->stoppable_id)->firstOrFail();
        $otherShipment = Shipment::query()->whereKey($other->stoppable_id)->firstOrFail();

        $this->actingAs($this->driver);

        $this->post(route('driver.stops.start', $stop), ['eta' => '09:40'])->assertSessionHasNoErrors();
        $stop->refresh();
        $this->assertSame(StopStatus::EnRoute, $stop->status);
        $this->assertSame('09:40', $stop->eta_at?->setTimezone($this->tenant->timezone ?? 'America/Toronto')->format('H:i'));
        $this->assertSame(ShipmentStatus::Out, $shipment->refresh()->status);

        // Not their truck.
        $this->post(route('driver.stops.start', $other))->assertNotFound();
        $this->get(route('shipments.deliver', $otherShipment))->assertNotFound();
        $this->post(route('shipments.complete', $otherShipment), ['name' => 'X', 'signature' => self::SIGNATURE])->assertNotFound();

        // Deliveries finish with proof of delivery, not "done".
        $this->post(route('driver.stops.done', $stop))->assertSessionHasErrors();

        $this->get(route('shipments.deliver', $shipment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('shipment.stop_id', $stop->id));
        $this->post(route('shipments.complete', $shipment), ['name' => 'Jen Okafor', 'signature' => self::SIGNATURE])->assertSessionHasNoErrors();

        $this->assertSame(ShipmentStatus::Delivered, $shipment->refresh()->status);
        $this->assertSame(StopStatus::Done, $stop->refresh()->status);
    }

    public function test_drivers_finish_pallet_and_rental_runs(): void
    {
        $stop = Stop::query()->create([
            'stoppable_type' => 'organization',
            'stoppable_id' => $this->org->id,
            'kind' => 'pallet_pickup',
            'truck_id' => $this->truck2->id,
            'date' => now()->toDateString(),
            'starts_at' => '14:00',
            'status' => StopStatus::Scheduled,
            'address' => ['line1' => '9 Kerr St', 'city' => 'Waterdown'],
        ]);

        $this->actingAs($this->driver);
        $this->get(route('driver.today'))->assertInertia(fn (Assert $page) => $page->where('stops.0.kind_label', 'Pallet pickup'));
        $this->post(route('driver.stops.done', $stop))->assertRedirect();

        $this->assertSame(StopStatus::Done, $stop->refresh()->status);
        $this->assertNotNull($stop->completed_at);
    }

    public function test_driver_phones_share_their_location(): void
    {
        $this->actingAs($this->driver);

        $this->get(route('driver.today'))->assertInertia(fn (Assert $page) => $page->where('shareLocation', true)->where('auth.can.drive', true));

        $this->postJson(route('driver.locate'), ['latitude' => 43.3343, 'longitude' => -79.8900, 'accuracy' => 12.4, 'speed' => 10])->assertNoContent();
        $this->postJson(route('driver.locate'), ['latitude' => 120, 'longitude' => -79.89])->assertUnprocessable();

        $ping = DriverPing::query()->sole();
        $this->assertSame($this->truck2->id, $ping->truck_id);
        $this->assertSame(12, $ping->accuracy_m);
        $this->assertSame('36.0', $ping->speed_kph);

        $this->truck2->refresh();
        $this->assertSame('43.3343000', $this->truck2->last_latitude);
        $this->assertNotNull($this->truck2->last_located_at);
        $this->assertNull($this->truck3->refresh()->last_located_at);

        // The board shows where the truck was last seen.
        $this->actingAs($this->owner);
        $this->get(route('dispatch.board'))->assertInertia(fn (Assert $page) => $page
            ->where('trucks.0.driver', 'Sam Rinaldi')
            ->where('trucks.0.location.latitude', 43.3343)
            ->where('trucks.1.location', null));

        // Only drivers send positions.
        $this->postJson(route('driver.locate'), ['latitude' => 43.3, 'longitude' => -79.8])->assertForbidden();

        // Old positions are pruned.
        $ping->forceFill(['recorded_at' => now()->subDays(DriverPing::KEEP_DAYS + 1)])->save();
        $this->artisan('model:prune', ['--model' => [DriverPing::class]])->assertSuccessful();
        $this->assertSame(0, DriverPing::query()->withoutGlobalScopes()->count());
    }

    public function test_owners_add_drivers_who_choose_a_password(): void
    {
        Notification::fake();

        $this->post(route('drivers.store'), ['name' => 'Dave Kowalchuk', 'email' => 'dave@example.com', 'truck_id' => $this->truck3->id])->assertSessionHasNoErrors();

        $dave = User::query()->where('email', 'dave@example.com')->firstOrFail();
        $this->assertSame(UserRole::Driver, $dave->role);
        $this->assertSame($this->tenant->id, $dave->tenant_id);
        $this->assertNull($dave->email_verified_at);
        $this->assertSame($dave->id, $this->truck3->refresh()->driver_id);
        Notification::assertSentTo($dave, DriverInviteMessage::class);

        $this->get(route('drivers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/drivers')
            ->has('drivers', 2)
            ->where('drivers.0.name', 'Dave Kowalchuk')
            ->where('drivers.0.trucks.0', 'Truck 3'));

        // Choosing a password from the link verifies their email.
        auth()->logout();
        $token = Password::broker()->createToken($dave);
        $this->post(route('password.update'), ['token' => $token, 'email' => 'dave@example.com', 'password' => 'a-Strong-pass-42!', 'password_confirmation' => 'a-Strong-pass-42!'])->assertSessionHasNoErrors();
        $this->assertNotNull($dave->refresh()->email_verified_at);

        // Trucks can point at a driver's sign-in, but only a driver's.
        $this->actingAs($this->owner);
        $this->put(route('trucks.update', $this->truck2), ['name' => 'Truck 2 · boom', 'kind' => 'material', 'driver_id' => $this->owner->id])->assertSessionHasErrors('driver_id');
        $this->put(route('trucks.update', $this->truck2), ['name' => 'Truck 2 · boom', 'kind' => 'material', 'driver_id' => $dave->id])->assertSessionHasNoErrors();
        $this->assertSame($dave->id, $this->truck2->refresh()->driver_id);

        $this->delete(route('drivers.destroy', $dave))->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $dave->id]);
        $this->assertNull($this->truck2->refresh()->driver_id);

        // Not someone who isn't a driver.
        $this->delete(route('drivers.destroy', $this->owner))->assertNotFound();

        // Staff can't add drivers.
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Staff]);
        $this->actingAs($staff);
        $this->get(route('drivers.index'))->assertForbidden();
    }

    public function test_contractors_see_stops_before_theirs_and_the_truck_when_next(): void
    {
        $first = $this->loadedStop($this->truck2, '09:00');
        $second = $this->loadedStop($this->truck2, '13:00', '9 Kerr St');
        $tracking = app(DeliveryTracking::class);

        // Off until the yard turns it on.
        $this->assertNull($tracking->forStop($second));

        $this->tenant->update(['settings' => ['delivery' => ['portal_stops_ahead' => true, 'portal_truck_location' => true]]]);
        CurrentTenant::set($this->tenant->refresh());

        $this->assertSame(['stops_ahead' => 1, 'location' => null], $tracking->forStop($second->refresh()));
        $this->assertSame(['stops_ahead' => 0, 'location' => null], $tracking->forStop($first->refresh()));

        // On its way, with a fresh position: the truck shows for the next stop only.
        $this->actingAs($this->driver);
        $this->post(route('driver.stops.start', $first))->assertSessionHasNoErrors();
        app(DriverRun::class)->locate($this->driver, 43.3343, -79.89, 10, null);

        $this->assertSame(43.3343, $tracking->forStop($first->refresh())['location']['latitude'] ?? null);
        $this->assertNull($tracking->forStop($second->refresh())['location'] ?? null);

        // A stale position isn't shown.
        $this->truck2->forceFill(['last_located_at' => now()->subMinutes(DeliveryTracking::FRESH_MINUTES + 5)])->save();
        $this->assertNull($tracking->forStop($first->refresh())['location'] ?? null);
        $this->truck2->forceFill(['last_located_at' => now()])->save();

        // The portal order page carries it.
        $order = Shipment::query()->whereKey($first->stoppable_id)->firstOrFail()->order;
        auth()->logout();
        $this->withSession(['portal' => ['customer_id' => $this->mike->id, 'tenant_id' => $this->tenant->id, 'slug' => $this->tenant->slug]])
            ->get(route('portal.order', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.shipments.0.tracking.stops_ahead', 0)
                ->where('order.shipments.0.tracking.location.latitude', 43.3343));

        // Once delivered there's nothing to track.
        $first->forceFill(['status' => StopStatus::Done])->save();
        $this->assertNull($tracking->forStop($first->refresh()));
    }
}
