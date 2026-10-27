<?php

namespace Tests\Feature\YardRunner;

use App\Models\AuditEvent;
use App\Models\DriverPing;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Stop;
use App\Models\Tenant;
use App\Models\Truck;
use App\Models\User;
use App\Payments\CheckoutLinks;
use App\Payments\FakeCheckoutLinks;
use App\Support\Demo;
use App\Tax\FlatRateTaxCalculator;
use App\Tax\TaxCalculator;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoYardTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'gord.hessels@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoYardSeeder::class);
        CurrentTenant::set(null);
    }

    public function test_demo_pages_are_hidden_unless_turned_on(): void
    {
        config(['yardline.demo' => false]);

        $this->get('/demo')->assertNotFound();
        $this->post('/demo', ['email' => self::OWNER])->assertNotFound();
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('demo', false));
        $this->assertGuest();
    }

    public function test_visitors_can_sign_in_as_any_demo_account(): void
    {
        config(['yardline.demo' => true]);

        $this->get('/demo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/demo')
                ->has('accounts', count(DemoYardSeeder::ACCOUNTS)));

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('demo', true));
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('demo', true));

        $this->post('/demo', ['email' => self::OWNER])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::query()->where('email', self::OWNER)->firstOrFail());

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('isDemo', true));
    }

    public function test_demo_accounts_cannot_sign_in_with_a_password(): void
    {
        config(['yardline.demo' => true]);

        $owner = User::query()->where('email', self::OWNER)->firstOrFail();
        $owner->forceFill(['password' => 'password'])->save();

        $this->post(route('login.store'), ['email' => self::OWNER, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_demo_sign_in_cannot_reach_real_accounts(): void
    {
        config(['yardline.demo' => true]);

        $real = User::factory()->create(['email' => 'real@example.com']);
        CurrentTenant::set(null);

        $this->post('/demo', ['email' => $real->email])->assertNotFound();
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $real->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($real);
    }

    public function test_demo_accounts_cannot_change_account_or_business_settings(): void
    {
        config(['yardline.demo' => true]);
        $this->post('/demo', ['email' => self::OWNER]);

        $this->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => 'Hijacked', 'email' => 'hijack@example.com'])
            ->assertRedirect(route('profile.edit'));

        $this->from(route('business.edit'))
            ->patch(route('business.update'), ['name' => 'Hijacked', 'timezone' => 'America/Toronto'])
            ->assertRedirect(route('business.edit'));

        $this->delete(route('profile.destroy'), ['password' => 'x']);

        $this->assertDatabaseHas('users', ['email' => self::OWNER, 'name' => 'Gord Hessels']);
        $this->assertDatabaseHas('tenants', ['slug' => DemoYardSeeder::SLUG, 'name' => 'Escarpment Stone & Landscape Supply']);
    }

    public function test_the_demo_yard_never_uses_stripe(): void
    {
        config([
            'yardline.tax.driver' => 'stripe',
            'yardline.payments.driver' => 'stripe',
        ]);

        CurrentTenant::set(Demo::tenantId());

        $this->assertInstanceOf(FlatRateTaxCalculator::class, app(TaxCalculator::class));
        $this->assertInstanceOf(FakeCheckoutLinks::class, app(CheckoutLinks::class));
    }

    public function test_the_seeder_skips_when_the_demo_yard_exists(): void
    {
        $this->seed(DemoYardSeeder::class);

        $this->assertSame(1, Tenant::query()->where('slug', DemoYardSeeder::SLUG)->count());
    }

    public function test_reset_rebuilds_only_the_demo_yard(): void
    {
        $other = Tenant::factory()->create();
        $realProduct = CurrentTenant::run($other, fn () => Product::factory()->create());
        $oldDemoId = Demo::tenantId();

        $this->artisan('yardline:demo:reset', ['--force' => true])->assertSuccessful();

        $newDemoId = Demo::tenantId();
        $this->assertNotNull($newDemoId);
        $this->assertNotSame($oldDemoId, $newDemoId);
        $this->assertDatabaseMissing('tenants', ['id' => $oldDemoId]);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('tenant_id', $oldDemoId)->count());
        $this->assertGreaterThan(0, Order::query()->withoutGlobalScopes()->where('tenant_id', $newDemoId)->count());
        $this->assertSame(
            ['out', 'quote', 'reserved', 'returned'],
            Rental::query()->withoutGlobalScopes()->where('tenant_id', $newDemoId)->distinct()->orderBy('status')->pluck('status')->map(fn ($status) => $status->value)->all(),
        );

        $this->assertDatabaseHas('products', ['id' => $realProduct->id, 'tenant_id' => $other->id]);
    }

    public function test_visitors_can_try_the_contractor_portal(): void
    {
        config(['yardline.demo' => true]);

        $this->get(route('portal.login', DemoYardSeeder::SLUG))->assertOk()->assertInertia(fn (Assert $page) => $page->whereNot('demo', null));
        $this->post(route('portal.demo', DemoYardSeeder::SLUG))->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders')->has('calculator.pavers'));
        $this->get(route('portal.orders'))->assertOk();
        $this->get(route('portal.statement'))->assertOk();
    }

    public function test_the_demo_yard_has_portal_activity_and_history(): void
    {
        $tenant = Tenant::query()->where('slug', DemoYardSeeder::SLUG)->firstOrFail();

        CurrentTenant::run($tenant, function () {
            $this->assertTrue(Order::query()->where('source', 'portal')->where('status', 'draft')->exists());
            $this->assertTrue(Stop::query()->where('kind', 'pallet_pickup')->exists());
            $this->assertTrue(AuditEvent::query()->whereNotNull('customer_id')->exists());
            $this->assertTrue(AuditEvent::query()->where('subject_type', 'product')->where('event', 'updated')->exists());
            $this->assertGreaterThan(5, AuditEvent::query()->where('subject_type', 'order')->count());
        });
    }

    public function test_every_demo_page_renders(): void
    {
        config(['yardline.demo' => true]);
        $this->post('/demo', ['email' => self::OWNER]);

        foreach (['dashboard', 'orders.index', 'orders.queue', 'dispatch.board', 'counter.show', 'inventory.index', 'purchasing.index', 'returns.index', 'receivables.index', 'products.index', 'organizations.index', 'customers.index', 'price-lists.index', 'fleet.index', 'rentals.calendar', 'rentals.index', 'maintenance.index', 'rental-payments.index', 'audit.index', 'delivery-settings.edit', 'rental-settings.edit', 'time.board', 'time.timesheets.index', 'time.employees.index', 'time.kiosk', 'time.self.show', 'time-settings.edit'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_visitors_can_try_the_driver_app(): void
    {
        config(['yardline.demo' => true]);

        $this->post('/demo', ['email' => DemoYardSeeder::DEMO_DRIVER])->assertRedirect(route('driver.today'));

        // A full day: done first thing, on the way now, one this afternoon.
        $this->get(route('driver.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('driver/today')
                ->has('stops', 3)
                ->where('stops.0.status', 'done')
                ->where('stops.1.status', 'en_route')
                ->where('stops.2.status', 'scheduled')
                // The demo never asks a visitor's phone where it is.
                ->where('shareLocation', false));

        $this->get(route('orders.index'))->assertRedirect(route('driver.today'));

        $tenant = Tenant::query()->where('slug', DemoYardSeeder::SLUG)->firstOrFail();
        $before = CurrentTenant::run($tenant, fn () => DriverPing::query()->count());
        $this->postJson(route('driver.locate'), ['latitude' => 45.5, 'longitude' => -73.5])->assertNoContent();
        $this->assertSame($before, CurrentTenant::run($tenant, fn () => DriverPing::query()->count()));

        // The scheduler moves the demo truck instead.
        $truck = CurrentTenant::run($tenant, fn () => Truck::query()->where('name', 'Truck 2 · boom')->firstOrFail());
        CurrentTenant::run($tenant, fn () => $truck->forceFill(['last_located_at' => now()->subHour()])->save());
        $this->artisan('yardline:demo:drive')->assertSuccessful();
        CurrentTenant::run($tenant, fn () => $truck->refresh());
        $this->assertTrue($truck->last_located_at?->greaterThan(now()->subMinute()));
    }

    public function test_the_demo_portal_shows_the_truck_coming(): void
    {
        config(['yardline.demo' => true]);

        $this->post(route('portal.demo', DemoYardSeeder::SLUG))->assertRedirect(route('portal.home'));

        $tenant = Tenant::query()->where('slug', DemoYardSeeder::SLUG)->firstOrFail();
        [$next, $later] = CurrentTenant::run($tenant, fn () => [
            Order::query()->where('po_number', 'BW-HAMILTON-2')->firstOrFail(),
            Order::query()->where('po_number', 'BW-KERR-1')->firstOrFail(),
        ]);

        $this->get(route('portal.order', $next))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.shipments.0.tracking.stops_ahead', 0)
            ->whereNot('order.shipments.0.tracking.location', null));
        $this->get(route('portal.order', $later))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.shipments.0.tracking.stops_ahead', 1)
            ->where('order.shipments.0.tracking.location', null));
    }
}
