<?php

namespace Tests\Feature\YardRunner;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\StopStatus;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Models\StockLevel;
use App\Models\Stop;
use App\Models\Truck;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Everyone but Owner and Admin works only their yards: orders, the
 * counter, stock, the dispatch board and rentals show those yards, and a
 * record from another yard isn't found.
 */
class YardScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Location $waterdown;

    private Location $burlington;

    private Product $mortar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardrunner.tax.driver' => 'flat', 'yardrunner.tax.flat_rate' => 0.13, 'yardrunner.payments.driver' => 'fake']);

        $this->owner = $this->signInToNewTenant();
        CurrentTenant::get()?->forceFill(['rentals_enabled' => true])->save();
        $this->waterdown = Location::query()->firstOrFail();
        $this->waterdown->update(['name' => 'Waterdown']);
        $this->burlington = Location::factory()->create(['name' => 'Burlington', 'is_default' => false]);

        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000]);
        $ledger = app(InventoryLedger::class);
        $ledger->record($this->mortar, $this->waterdown, 40, StockMovementType::Receipt);
        $ledger->record($this->mortar, $this->burlington, 60, StockMovementType::Receipt);
    }

    /** Someone with this role who works only Waterdown. */
    private function atWaterdown(UserRole $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->owner->tenant_id]);
        $user->setRoles([$role])->save();
        $user->setYards([$this->waterdown->id]);

        return $user->refresh();
    }

    private function order(Location $yard): Order
    {
        return Order::factory()->create(['location_id' => $yard->id, 'status' => OrderStatus::Confirmed]);
    }

    public function test_the_orders_list_and_order_pages_follow_their_yards(): void
    {
        $mine = $this->order($this->waterdown);
        $theirs = $this->order($this->burlington);

        $this->actingAs($this->atWaterdown(UserRole::Sales));

        $this->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $mine->id));
        $this->get(route('orders.show', $mine))->assertOk();
        $this->get(route('orders.show', $theirs))->assertNotFound();
        $this->post(route('orders.cancel', $theirs))->assertNotFound();
        $this->assertSame(OrderStatus::Confirmed, $theirs->refresh()->status);

        // New orders only from their yards.
        $this->get(route('orders.create'))->assertInertia(fn (Assert $page) => $page
            ->has('locations', 1)
            ->where('locations.0.id', $this->waterdown->id));
        $this->post(route('orders.store'), ['type' => 'sale', 'location_id' => $this->burlington->id, 'fulfillment' => 'pickup'])
            ->assertSessionHasErrors('location_id');
        $this->post(route('orders.store'), ['type' => 'sale', 'location_id' => $this->waterdown->id, 'fulfillment' => 'pickup'])
            ->assertSessionHasNoErrors();
    }

    public function test_owners_see_every_yard(): void
    {
        $this->order($this->waterdown);
        $theirs = $this->order($this->burlington);

        $this->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->has('orders.data', 2));
        $this->get(route('orders.show', $theirs))->assertOk();
        $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page->has('rows', 2)->has('locations', 2));
    }

    public function test_the_counter_sells_from_their_yards(): void
    {
        $this->actingAs($this->atWaterdown(UserRole::Sales));

        // Asking for another yard gets theirs.
        $this->get(route('counter.show', ['yard' => $this->burlington->id]))->assertInertia(fn (Assert $page) => $page
            ->where('yard.id', $this->waterdown->id)
            ->has('yards', 1));

        $sale = [
            'settlement' => 'prepaid',
            'tender' => 'cash',
            'lines' => [['product_id' => $this->mortar->id, 'quantity' => 1]],
        ];
        $this->post(route('counter.store'), [...$sale, 'location_id' => $this->burlington->id])->assertSessionHasErrors('location_id');
        $this->post(route('counter.store'), [...$sale, 'location_id' => $this->waterdown->id])->assertSessionHasNoErrors();

        $receipt = $this->order($this->burlington);
        $this->get(route('counter.receipt', $receipt))->assertNotFound();
    }

    public function test_someone_with_no_yard_cant_open_the_counter(): void
    {
        $sales = User::factory()->create(['tenant_id' => $this->owner->tenant_id]);
        $sales->setRoles([UserRole::Sales])->save();
        $this->actingAs($sales->refresh());

        $this->get(route('counter.show'))->assertForbidden();
    }

    public function test_stock_follows_their_yards(): void
    {
        $this->actingAs($this->atWaterdown(UserRole::Purchasing));
        $theirs = StockLevel::query()->where('location_id', $this->burlington->id)->sole();

        $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.location_id', $this->waterdown->id)
            ->has('locations', 1));
        // Filtering by a yard they don't work shows theirs.
        $this->get(route('inventory.index', ['location_id' => $this->burlington->id]))->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.location_id', $this->waterdown->id));

        $this->patch(route('stock-levels.update', $theirs), ['reorder_point' => 5])->assertNotFound();
        $this->post(route('inventory.adjustments.store'), ['product_id' => $this->mortar->id, 'location_id' => $this->burlington->id, 'mode' => 'adjust', 'quantity' => 1])
            ->assertSessionHasErrors('location_id');
        $this->post(route('cycle-counts.store'), ['location_id' => $this->burlington->id])->assertSessionHasErrors('location_id');

        // Stock can go out to another yard, not come out of one.
        $this->post(route('stock-transfers.store'), ['from_location_id' => $this->burlington->id, 'to_location_id' => $this->waterdown->id, 'lines' => [['product_id' => $this->mortar->id, 'quantity' => 5]]])
            ->assertSessionHasErrors('from_location_id');
        $this->post(route('stock-transfers.store'), ['from_location_id' => $this->waterdown->id, 'to_location_id' => $this->burlington->id, 'lines' => [['product_id' => $this->mortar->id, 'quantity' => 5]]])
            ->assertSessionDoesntHaveErrors('from_location_id');
    }

    public function test_the_dispatch_board_shows_their_yards_trucks_and_stops(): void
    {
        $ours = Truck::query()->create(['name' => 'Waterdown boom', 'location_id' => $this->waterdown->id, 'is_active' => true]);
        $shared = Truck::query()->create(['name' => 'Spare', 'location_id' => null, 'is_active' => true]);
        $other = Truck::query()->create(['name' => 'Burlington flatbed', 'location_id' => $this->burlington->id, 'is_active' => true]);

        $mine = $this->stop($this->order($this->waterdown));
        $theirs = $this->stop($this->order($this->burlington));
        // Another yard's order on our truck: it's on our board.
        $onOurTruck = $this->stop($this->order($this->burlington), $ours);

        $this->actingAs($this->atWaterdown(UserRole::Dispatcher));

        $board = $this->get(route('dispatch.board'))->assertOk()->viewData('page')['props'];
        $trucks = collect($board['trucks']);

        $this->assertEqualsCanonicalizing([$ours->id, $shared->id], $trucks->pluck('id')->all());
        $this->assertSame([$mine->id], collect($board['unscheduled'])->pluck('id')->all());
        $this->assertSame([$onOurTruck->id], collect($trucks->firstWhere('id', $ours->id)['stops'])->pluck('id')->all());

        $this->patch(route('stops.schedule', $theirs), ['truck_id' => $ours->id, 'date' => $this->today(), 'starts_at' => '09:00'])->assertNotFound();
        $this->patch(route('stops.schedule', $mine), ['truck_id' => $other->id, 'date' => $this->today(), 'starts_at' => '09:00'])->assertSessionHasErrors('truck_id');
        $this->patch(route('stops.schedule', $mine), ['truck_id' => $shared->id, 'date' => $this->today(), 'starts_at' => '09:00'])->assertSessionHasNoErrors();
        $this->put(route('dispatch.driver', $other), ['date' => $this->today(), 'user_id' => null])->assertNotFound();
    }

    public function test_rentals_follow_their_yards(): void
    {
        $ourUnit = RentalUnit::factory()->create(['location_id' => $this->waterdown->id]);
        $theirUnit = RentalUnit::factory()->create(['location_id' => $this->burlington->id]);
        $mine = Rental::factory()->create(['location_id' => $this->waterdown->id]);
        $theirs = Rental::factory()->create(['location_id' => $this->burlington->id]);

        $this->actingAs($this->atWaterdown(UserRole::RentalDesk));

        $this->get(route('rentals.index'))->assertInertia(fn (Assert $page) => $page
            ->has('rentals.data', 1)
            ->where('rentals.data.0.id', $mine->id));
        $this->get(route('rentals.show', $mine))->assertOk();
        $this->get(route('rentals.show', $theirs))->assertNotFound();
        $this->post(route('rentals.cancel', $theirs))->assertNotFound();

        $this->get(route('rentals.calendar'))->assertInertia(fn (Assert $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('id')->all() === [$ourUnit->id]));
        $this->get(route('rentals.create'))->assertInertia(fn (Assert $page) => $page
            ->has('locations', 1)
            ->where('products', fn ($products) => collect($products)->flatMap(fn ($p) => collect($p['units'])->pluck('id'))->values()->all() === [$ourUnit->id]));

        $this->actingAs($this->atWaterdown(UserRole::ServiceTech));
        $this->get(route('fleet.index'))->assertInertia(fn (Assert $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('id')->all() === [$ourUnit->id]));
        $this->get(route('fleet.units.show', $theirUnit))->assertNotFound();
        $this->get(route('fleet.units.show', $ourUnit))->assertOk();
    }

    private function today(): string
    {
        return now(CurrentTenant::get()->timezone ?? 'America/Toronto')->toDateString();
    }

    private function stop(Order $order, ?Truck $truck = null): Stop
    {
        return Stop::query()->create([
            'stoppable_type' => 'order',
            'stoppable_id' => $order->id,
            'kind' => 'delivery',
            'truck_id' => $truck?->id,
            // The board's day is the yard's today, not UTC's.
            'date' => $this->today(),
            'starts_at' => $truck ? '10:00' : null,
            'status' => $truck ? StopStatus::Scheduled : StopStatus::Unscheduled,
            'address' => ['line1' => '1 Main St', 'city' => 'Waterdown'],
        ]);
    }
}
