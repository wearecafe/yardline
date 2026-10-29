<?php

namespace Tests\Feature\YardRunner;

use App\Access\AccessSettings;
use App\Access\RouteAccess;
use App\Enums\PaymentTerms;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\AuditEvent;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Job-based roles: each role reaches the screens its job needs, people
 * can hold several, judgment calls are permissions with role defaults
 * the business can change, and refunds are limited per role.
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Location $yard;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardrunner.tax.driver' => 'flat', 'yardrunner.tax.flat_rate' => 0.13, 'yardrunner.payments.driver' => 'fake']);

        $this->owner = $this->signInToNewTenant();
        $this->tenant = CurrentTenant::get();
        $this->yard = Location::query()->firstOrFail();
    }

    private function person(UserRole ...$roles): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->setRoles($roles)->save();

        if ($user->isYardScoped()) {
            $user->setYards([$this->yard->id]);
        }

        return $user->refresh();
    }

    public function test_older_role_values_read_as_the_new_roles(): void
    {
        $this->assertSame(UserRole::Sales, UserRole::fromValue('staff'));
        $this->assertSame(UserRole::YardCrew, UserRole::fromValue('picker'));
        $this->assertSame(UserRole::Dispatcher, UserRole::fromValue('dispatcher'));
        $this->assertNull(UserRole::fromValue('nobody'));
    }

    public function test_each_role_reaches_its_own_screens(): void
    {
        $sales = $this->person(UserRole::Sales);
        $this->actingAs($sales);
        $this->get(route('counter.show'))->assertOk();
        $this->get(route('orders.index'))->assertOk();
        $this->get(route('price-lists.index'))->assertRedirect(route('dashboard'));
        $this->post(route('price-lists.store'), ['name' => 'Cheap'])->assertForbidden();
        $this->get(route('receivables.index'))->assertRedirect(route('dashboard'));

        $accounts = $this->person(UserRole::Accounts);
        $this->actingAs($accounts);
        $this->get(route('receivables.index'))->assertOk();
        // Orders to read, not to change.
        $this->get(route('orders.index'))->assertOk();
        $this->post(route('orders.store'), ['type' => 'sale'])->assertForbidden();

        $dispatcher = $this->person(UserRole::Dispatcher);
        $this->actingAs($dispatcher);
        $this->get(route('dispatch.board'))->assertOk();
        $this->get(route('orders.index'))->assertRedirect(route('dispatch.board'));

        $tech = $this->person(UserRole::ServiceTech);
        $this->actingAs($tech);
        $this->get(route('maintenance.index'))->assertOk();
        $this->get(route('counter.show'))->assertRedirect(route('maintenance.index'));

        $purchasing = $this->person(UserRole::Purchasing);
        $this->actingAs($purchasing);
        $this->get(route('purchasing.index'))->assertOk();
        $this->get(route('counter.show'))->assertRedirect(route('inventory.index'));

        $rentals = $this->person(UserRole::RentalDesk);
        $this->actingAs($rentals);
        $this->get(route('rentals.index'))->assertOk();
        $this->get(route('counter.show'))->assertRedirect(route('rentals.index'));
    }

    public function test_roles_add_up(): void
    {
        $both = $this->person(UserRole::Sales, UserRole::Dispatcher);
        $this->actingAs($both);

        $this->get(route('counter.show'))->assertOk();
        $this->get(route('dispatch.board'))->assertOk();
        $this->assertSame(UserRole::Sales, $both->role);
        $this->assertTrue(RouteAccess::allowsRoute($both, 'stops.schedule', false));
    }

    public function test_the_sidebar_and_queue_follow_the_roles(): void
    {
        $crew = $this->person(UserRole::YardCrew);
        $this->actingAs($crew)->get(route('orders.queue'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.routes', fn ($routes) => collect($routes)->get('orders.queue') === true && collect($routes)->get('counter.show') === false)
            ->where('auth.can.workQueue', true)
            ->where('auth.can.seePrices', false)
            ->where('auth.home', 'orders.queue'));

        $sales = $this->person(UserRole::Sales);
        $this->actingAs($sales)->get(route('orders.queue'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.workQueue', false)
            ->where('auth.can.seePrices', true));
    }

    public function test_permissions_come_from_roles_and_can_be_changed_per_person(): void
    {
        $accounts = $this->person(UserRole::Accounts);
        $sales = $this->person(UserRole::Sales);

        $this->assertTrue($accounts->hasPermission(Permission::OverrideCredit));
        $this->assertFalse($sales->hasPermission(Permission::OverrideCredit));
        $this->assertTrue($this->owner->hasPermission(Permission::SeeProfit));

        $sales->forceFill(['permissions' => ['override_credit' => true]])->save();
        $this->assertTrue($sales->refresh()->hasPermission(Permission::OverrideCredit));

        // Discount exempt products means nothing without manual prices.
        $sales->forceFill(['permissions' => ['discount_exempt' => true]])->save();
        $this->assertFalse($sales->refresh()->hasPermission(Permission::DiscountExempt));
    }

    public function test_the_business_changes_role_defaults_and_refund_limits(): void
    {
        $sales = $this->person(UserRole::Sales);
        $this->assertSame(25_000, $sales->refundLimit());

        $this->get(route('roles.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/roles')
            // Time & attendance only has nothing to set.
            ->has('roles', count(UserRole::cases()) - 1));

        $this->put(route('roles.update'), [
            'role_permissions' => ['sales' => ['set_manual_prices']],
            'refund_limits' => ['sales' => '500', 'accounts' => null],
        ])->assertSessionHasNoErrors();

        $sales->refresh()->unsetRelation('tenant');
        $this->assertTrue($sales->hasPermission(Permission::SetManualPrices));
        $this->assertSame(50_000, $sales->refundLimit());
        $this->assertTrue(AuditEvent::query()->where('event', 'roles updated')->exists());

        // An admin can change roles, but not hand out the P&L.
        $admin = $this->person(UserRole::Admin);
        $this->actingAs($admin)->put(route('roles.update'), ['role_permissions' => ['sales' => ['set_manual_prices', 'see_profit']]])
            ->assertSessionHasNoErrors();
        $this->assertFalse(AccessSettings::for($this->tenant->refresh())->roleGives(UserRole::Sales, Permission::SeeProfit));

        // Sales can't change roles at all.
        $this->actingAs($sales)->put(route('roles.update'), ['refund_limits' => ['sales' => null]])->assertForbidden();
    }

    public function test_refunds_above_a_role_limit_need_someone_to_approve(): void
    {
        $mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000]);
        app(InventoryLedger::class)->record($mortar, $this->yard, 100, StockMovementType::Receipt);
        $org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create();

        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => $org->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $order), ['product_id' => $mortar->id, 'quantity' => 50])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $line = $order->lines()->firstOrFail();
        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 50, 'restock' => true]],
            'reason' => 'Wrong mortar',
            'restocking_percent' => 0,
        ])->assertRedirect();
        $credit = CreditNote::query()->firstOrFail();
        $this->assertGreaterThan(25_000, $credit->total_cents);

        $sales = $this->person(UserRole::Sales);
        $this->actingAs($sales);

        // Up to their $250 limit, on their own.
        $this->post(route('credit-notes.refund', $credit), ['tender' => 'cheque', 'amount' => '100'])->assertSessionHasNoErrors();
        $this->assertSame(10_000, $credit->refresh()->refunded_cents);

        // Above it, someone who can refund above the limit approves.
        $this->post(route('credit-notes.refund', $credit), ['tender' => 'cheque'])->assertSessionHasErrors('approver_id');
        $this->post(route('credit-notes.refund', $credit), ['tender' => 'cheque', 'approver_id' => $sales->id, 'approver_secret' => 'password'])
            ->assertSessionHasErrors('approver_id');
        $this->post(route('credit-notes.refund', $credit), ['tender' => 'cheque', 'approver_id' => $this->owner->id, 'approver_secret' => 'wrong'])
            ->assertSessionHasErrors('approver_secret');
        $this->assertSame(10_000, $credit->refresh()->refunded_cents);

        $this->post(route('credit-notes.refund', $credit), ['tender' => 'cheque', 'approver_id' => $this->owner->id, 'approver_secret' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame($credit->total_cents, $credit->refresh()->refunded_cents);

        $approved = AuditEvent::query()->where('event', 'refund approved')->sole();
        $this->assertSame($sales->id, $approved->user_id);
        $this->assertStringContainsString($this->owner->name, (string) $approved->note);

        // Yard crew can't refund at all, even a dollar.
        $crew = $this->person(UserRole::YardCrew);
        $this->assertFalse($crew->canRefund(100));
    }

    public function test_purchasing_edits_products_but_not_what_they_sell_for(): void
    {
        $product = Product::factory()->create(['sku' => 'MOR-S30', 'name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_695, 'cost_cents' => 900]);
        $purchasing = $this->person(UserRole::Purchasing);

        $this->actingAs($purchasing)->put(route('products.update', $product), [
            'sku' => 'MOR-S30',
            'name' => 'Type S mortar, 30 kg',
            'kind' => 'sale',
            'unit' => 'bag',
            'price' => '1.00',
            'cost' => '9.50',
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('Type S mortar, 30 kg', $product->name);
        $this->assertSame(1_695, $product->price_cents);
        $this->assertSame(950, $product->cost_cents);

        // Sales sees the product, but not its cost.
        $sales = $this->person(UserRole::Sales);
        $this->actingAs($sales)->get(route('products.edit', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.cost_cents', null)
            ->where('can.seeCosts', false));
        $this->put(route('products.update', $product), ['sku' => 'X', 'name' => 'X', 'kind' => 'sale', 'unit' => 'bag', 'price' => '1'])->assertForbidden();
    }
}
