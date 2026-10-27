<?php

namespace Tests\Feature\Yardline;

use App\Enums\ShipmentStatus;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Fulfillment\PickingSettings;
use App\Inventory\InventoryLedger;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentCheck;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The order picker role, the yard's fulfillment stages, and quality-check
 * sign-offs at Picked and Loaded.
 */
class OrderPickingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Location $yard;

    private Product $sand;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);
        Storage::fake(config('yardline.files_disk'));

        $this->owner = $this->signInToNewTenant();
        $this->tenant = CurrentTenant::get();
        $this->yard = Location::query()->firstOrFail();

        $this->sand = Product::factory()->create(['name' => 'Polymeric sand', 'sku' => 'SND-POLY', 'unit' => Unit::Bag, 'price_cents' => 4_000, 'weight_kg' => 25]);
        app(InventoryLedger::class)->record($this->sand, $this->yard, 100, StockMovementType::Receipt);

        $this->org = Organization::factory()->withCredit(10_000_000)->create();
    }

    /**
     * @param  array<string, mixed>  $picking
     */
    private function picking(array $picking): void
    {
        $this->tenant->update(['settings' => [...($this->tenant->settings ?? []), 'picking' => $picking]]);
        CurrentTenant::set($this->tenant->refresh());
    }

    private function user(UserRole $role, string $name): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role, 'name' => $name]);
    }

    private function confirmedOrder(?Location $yard = null, string $fulfillment = 'pickup'): Order
    {
        $this->actingAs($this->owner);

        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $this->org->id,
            'location_id' => ($yard ?? $this->yard)->id,
            'fulfillment' => $fulfillment,
            ...($fulfillment === 'delivery' ? ['delivery_address' => ['line1' => '12 King St', 'city' => 'Hamilton', 'postal_code' => 'L8B 1A1']] : []),
        ])->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->sand->id, 'quantity' => 20])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    /** Starts the pick list as $user and confirms every line. */
    private function pickAll(Order $order, User $user): Shipment
    {
        $this->actingAs($user)->post(route('orders.pick', $order))->assertSessionHasNoErrors();
        $shipment = Shipment::query()->latest('id')->firstOrFail();

        foreach ($shipment->lines as $line) {
            $this->post(route('shipments.lines.record', [$shipment, $line]), ['picked_quantity' => $line->quantity])->assertSessionHasNoErrors();
        }

        return $shipment;
    }

    private function onHand(): string
    {
        return app(InventoryLedger::class)->onHand($this->sand, $this->yard);
    }

    public function test_a_load_waits_at_picked_until_an_allowed_role_signs_off(): void
    {
        $this->picking(['checks' => ['picked' => ['required' => true, 'roles' => ['admin'], 'different_user' => true]]]);
        $picker = $this->user(UserRole::Picker, 'Pat Picker');
        $picker->setYards([$this->yard->id]);
        $admin = $this->user(UserRole::Admin, 'Alex Admin');

        $order = $this->confirmedOrder();
        $shipment = $this->pickAll($order, $picker);
        $this->assertSame($picker->id, $shipment->refresh()->picker_id);

        // Straight to loading isn't allowed while Picked has to be checked.
        $this->post(route('shipments.load', $shipment))->assertSessionHasErrors('order');

        $this->post(route('shipments.picked', $shipment))->assertSessionHasNoErrors();
        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Picked, $shipment->status);
        $this->assertSame('picked', $shipment->check_stage);
        $this->assertSame($picker->id, $shipment->picked_by);

        // Waiting for a check: it can't be loaded, and the picker can't sign off.
        $this->post(route('shipments.load', $shipment))->assertSessionHasErrors('order');
        $this->post(route('shipments.sign-off', $shipment))->assertSessionHasErrors('order');

        $this->actingAs($this->owner)->get(route('orders.queue', ['checks' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('awaitingChecks', 1));

        $this->actingAs($admin)->post(route('shipments.sign-off', $shipment), ['notes' => 'Counted 20 bags'])->assertSessionHasNoErrors();
        $shipment->refresh();
        $this->assertNull($shipment->check_stage);
        $this->assertSame(ShipmentStatus::Picked, $shipment->status);
        $this->assertSame('100.000', $this->onHand(), 'Nothing leaves until it is loaded.');

        $check = ShipmentCheck::query()->sole();
        $this->assertTrue($check->signedOff());
        $this->assertSame($admin->id, $check->user_id);
        $this->assertSame($picker->id, $check->completed_by);
        $this->assertSame('Counted 20 bags', $check->notes);

        $this->actingAs($picker)->post(route('shipments.load', $shipment))->assertSessionHasNoErrors();
        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Ready, $shipment->status);
        $this->assertNotNull($shipment->released_at);
        $this->assertSame('80.000', $this->onHand());
    }

    public function test_a_rejected_load_goes_back_a_stage_with_the_reason(): void
    {
        $this->picking(['checks' => ['loaded' => ['required' => true, 'roles' => ['staff'], 'different_user' => true]]]);
        $jess = $this->user(UserRole::Staff, 'Jess');
        $sam = $this->user(UserRole::Staff, 'Sam');

        $order = $this->confirmedOrder(fulfillment: 'delivery');
        $shipment = $this->pickAll($order, $jess);
        $this->post(route('shipments.picked', $shipment))->assertSessionHasNoErrors();
        $this->post(route('shipments.load', $shipment))->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Loaded, $shipment->status);
        $this->assertSame('loaded', $shipment->check_stage);
        $this->assertNull($shipment->released_at);
        $this->assertNull($shipment->stop, 'It reaches the dispatch board once checked.');
        $this->assertSame('100.000', $this->onHand());

        // Nobody checks their own work.
        $this->post(route('shipments.sign-off', $shipment))->assertSessionHasErrors('order');
        $this->post(route('shipments.complete', $shipment), ['name' => 'Pat'])->assertSessionHasErrors();

        $this->actingAs($sam)->post(route('shipments.reject', $shipment))->assertSessionHasErrors('reason');
        $this->post(route('shipments.reject', $shipment), ['reason' => 'Wrong colour run'])->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Picked, $shipment->status);
        $this->assertNull($shipment->check_stage);
        $this->assertNull($shipment->loaded_by);
        $this->assertSame('Wrong colour run', ShipmentCheck::query()->sole()->reason);

        $this->get(route('shipments.pick', $shipment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sent_back.reason', 'Wrong colour run')
                ->where('next_step.action', 'load'));

        // Sam loads it this time, so Jess can check it.
        $this->post(route('shipments.load', $shipment))->assertSessionHasNoErrors();
        $this->actingAs($jess)->post(route('shipments.sign-off', $shipment))->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Loaded, $shipment->status);
        $this->assertNotNull($shipment->released_at);
        $this->assertNotNull($shipment->stop);
        $this->assertSame('80.000', $this->onHand());
        $this->assertSame(2, $shipment->checks()->count());
    }

    public function test_a_check_can_let_someone_sign_off_their_own_work(): void
    {
        $this->picking(['checks' => ['picked' => ['required' => true, 'roles' => ['owner'], 'different_user' => false]]]);

        $order = $this->confirmedOrder();
        $shipment = $this->pickAll($order, $this->owner);
        $this->post(route('shipments.picked', $shipment))->assertSessionHasNoErrors();
        $this->post(route('shipments.sign-off', $shipment))->assertSessionHasNoErrors();

        $this->assertNull($shipment->refresh()->check_stage);
    }

    public function test_stages_that_are_off_are_skipped(): void
    {
        // Picked off: loading goes straight from Picking. Loaded off: a
        // delivery waits for its truck as ready for dispatch.
        $this->picking(['stages' => ['picking' => true, 'picked' => false, 'loaded' => false, 'out' => true]]);

        $order = $this->confirmedOrder(fulfillment: 'delivery');
        $shipment = $this->pickAll($order, $this->owner);
        $this->post(route('shipments.picked', $shipment))->assertSessionHasErrors('order');
        $this->post(route('shipments.load', $shipment))->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Ready, $shipment->status);
        $this->assertNotNull($shipment->released_at);
        $this->assertNotNull($shipment->stop);
        $this->assertSame('80.000', $this->onHand());

        $this->get(route('orders.queue'))->assertInertia(fn (Assert $page) => $page
            ->where('columns.2.key', 'loaded')
            ->where('columns.2.title', 'Ready for dispatch')
            ->where('columns.2.cards.0.shipment_id', $shipment->id)
            ->where('columns', fn ($columns) => collect($columns)->pluck('key')->doesntContain('picked')));
    }

    public function test_with_loaded_off_a_picked_load_goes_straight_out(): void
    {
        $this->picking(['stages' => ['loaded' => false]]);

        $order = $this->confirmedOrder();
        $shipment = $this->pickAll($order, $this->owner);
        $this->post(route('shipments.picked', $shipment))->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Ready, $shipment->status);
        $this->assertNotNull($shipment->released_at);
        $this->assertSame('80.000', $this->onHand());
    }

    public function test_switching_a_check_off_still_needs_waiting_loads_signed_off(): void
    {
        $this->picking(['checks' => ['picked' => ['required' => true, 'roles' => ['admin'], 'different_user' => true]]]);
        $admin = $this->user(UserRole::Admin, 'Alex');

        $order = $this->confirmedOrder();
        $shipment = $this->pickAll($order, $this->owner);
        $this->post(route('shipments.picked', $shipment))->assertSessionHasNoErrors();

        // The business switches the Picked stage (and so its check) off.
        $this->picking(['stages' => ['picked' => false], 'checks' => ['picked' => ['required' => true, 'roles' => ['admin']]]]);
        $this->assertFalse(PickingSettings::current()->requiresCheck('picked'));

        $this->post(route('shipments.load', $shipment))->assertSessionHasErrors('order');
        $this->actingAs($admin)->post(route('shipments.sign-off', $shipment))->assertSessionHasNoErrors();
        $this->post(route('shipments.load', $shipment))->assertSessionHasNoErrors();
        $this->assertTrue($shipment->refresh()->isReleased());
    }

    public function test_order_pickers_only_see_their_yards_and_never_money(): void
    {
        $other = Location::factory()->create(['name' => 'Stoney Creek yard']);
        app(InventoryLedger::class)->record($this->sand, $other, 100, StockMovementType::Receipt);

        $mine = $this->confirmedOrder();
        $theirs = $this->confirmedOrder($other);

        $picker = $this->user(UserRole::Picker, 'Pat');
        $picker->setYards([$this->yard->id]);
        $this->actingAs($picker);

        $this->get(route('orders.queue', ['card' => "o{$mine->id}"]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/queue')
                ->where('columns.0.cards', fn ($cards) => collect($cards)->pluck('order_id')->all() === [$mine->id])
                ->where('detail.balance_cents', null)
                ->where('detail.delivery_fee_cents', null)
                ->where('detail.price_tier', null)
                ->where('pickers', [['id' => $picker->id, 'name' => 'Pat']]));

        // Another yard's order is out of reach.
        $this->post(route('orders.pick', $theirs))->assertNotFound();

        // Everything except the queue sends them back to it.
        $this->get(route('dashboard'))->assertRedirect(route('orders.queue'));
        $this->get(route('orders.show', $mine))->assertRedirect(route('orders.queue'));
        $this->get(route('inventory.index'))->assertRedirect(route('orders.queue'));
        $this->post(route('orders.store'), ['type' => 'sale'])->assertForbidden();

        // They claim and pick their own order, but can't cancel a pick list.
        $this->post(route('orders.pick', $mine), ['picker_id' => $this->owner->id])->assertSessionHasNoErrors();
        $shipment = Shipment::query()->latest('id')->firstOrFail();
        $this->assertSame($picker->id, $shipment->picker_id);
        $this->post(route('shipments.cancel', $shipment))->assertForbidden();
        $this->patch(route('shipments.assign', $shipment), ['picker_id' => $this->owner->id])->assertForbidden();
    }

    public function test_settings_page_saves_stages_and_checks(): void
    {
        $this->get(route('picking-settings.edit'))->assertOk();

        $valid = [
            'stages' => ['picking' => true, 'picked' => true, 'loaded' => true, 'out' => false],
            'checks' => [
                'picked' => ['required' => true, 'roles' => ['admin', 'picker'], 'different_user' => true],
                'loaded' => ['required' => false, 'roles' => [], 'different_user' => true],
            ],
        ];

        // A check needs its stage on, and at least one role.
        $this->put(route('picking-settings.update'), array_replace_recursive($valid, ['stages' => ['picked' => false]]))
            ->assertSessionHasErrors('checks.picked.required');
        $noRoles = $valid;
        $noRoles['checks']['picked']['roles'] = [];
        $this->put(route('picking-settings.update'), $noRoles)->assertSessionHasErrors('checks.picked.roles');

        $this->put(route('picking-settings.update'), $valid)->assertSessionHasNoErrors();

        $settings = PickingSettings::for($this->tenant->refresh());
        $this->assertFalse($settings->stageOn('out'));
        $this->assertTrue($settings->requiresCheck('picked'));
        $this->assertEquals([UserRole::Admin, UserRole::Picker], $settings->checkRoles('picked'));
        $this->assertFalse($settings->requiresCheck('loaded'));

        // Only owners and admins change it.
        $this->actingAs($this->user(UserRole::Staff, 'Jess'))->put(route('picking-settings.update'), $valid)->assertForbidden();
    }

    public function test_owner_adds_an_order_picker_who_switches_in_with_a_pin(): void
    {
        $other = Location::factory()->create(['name' => 'Stoney Creek yard']);

        $this->post(route('users.store'), [
            'name' => 'Pat Picker',
            'email' => 'pat@example.com',
            'role' => 'picker',
            'location_ids' => [$other->id],
            'pin' => '4821',
        ])->assertSessionHasNoErrors();

        $picker = User::query()->where('email', 'pat@example.com')->firstOrFail();
        $this->assertSame(UserRole::Picker, $picker->role);
        $this->assertSame([$other->id], $picker->pickingYardIds());
        $this->assertNotSame('4821', $picker->pin, 'PINs are hashed.');

        // A short or non-numeric PIN is refused.
        $this->patch(route('users.update', $picker), ['pin' => '12'])->assertSessionHasErrors('pin');

        // On a shared tablet, someone signed in switches to the picker.
        $staff = $this->user(UserRole::Staff, 'Jess');
        $this->actingAs($staff);
        $this->post(route('picker.switch'), ['user_id' => $picker->id, 'pin' => '0000'])->assertSessionHasErrors('pin');
        $this->assertAuthenticatedAs($staff);

        $this->post(route('picker.switch'), ['user_id' => $picker->id, 'pin' => '4821'])->assertRedirect(route('orders.queue'));
        $this->assertAuthenticatedAs($picker->refresh());

        // Only order picker accounts can be switched to by PIN.
        $this->owner->forceFill(['pin' => '1111'])->save();
        $this->post(route('picker.switch'), ['user_id' => $this->owner->id, 'pin' => '1111'])->assertSessionHasErrors('pin');
        $this->assertAuthenticatedAs($picker);

        // Changing them to staff clears their yards and PIN.
        $this->actingAs($this->owner)->patch(route('users.update', $picker), ['role' => 'staff'])->assertSessionHasNoErrors();
        $picker->refresh();
        $this->assertNull($picker->pin);
        $this->assertSame(0, $picker->yards()->count());
    }
}
