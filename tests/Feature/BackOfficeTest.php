<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Jobs\SendToQuickBooks;
use App\Models\AuditEvent;
use App\Models\Integration;
use App\Models\Location;
use App\Models\Order;
use App\Models\QuickBooksSync;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\UserInviteMessage;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The back office (/admin) where YardRunner staff look after tenants.
 */
class BackOfficeTest extends TestCase
{
    use RefreshDatabase;

    private function signInAsStaff(bool $twoFactor = true): User
    {
        CurrentTenant::set(null);

        $staff = User::factory()
            ->state(['tenant_id' => null, 'role' => UserRole::Sales])
            ->when($twoFactor, fn ($f) => $f->withTwoFactor())
            ->create();
        $staff->forceFill(['is_platform_staff' => true])->save();

        $this->actingAs($staff);

        return $staff;
    }

    /**
     * A tenant with a yard and some confirmed sales orders.
     */
    private function tenantWithOrders(string $name, int $orders, array $attributes = []): Tenant
    {
        $tenant = Tenant::factory()->create(['name' => $name, ...$attributes]);

        CurrentTenant::run($tenant, function () use ($orders) {
            $location = Location::factory()->create(['is_default' => true]);

            for ($i = 0; $i < $orders; $i++) {
                Order::factory()->create([
                    'location_id' => $location->id,
                    'status' => OrderStatus::Confirmed,
                    'confirmed_at' => now()->subDays(2),
                    'subtotal_cents' => 10_000,
                    'total_cents' => 11_300,
                ]);
            }
        });

        return $tenant;
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_tenant_users_never_see_the_back_office(): void
    {
        $this->signInToNewTenant(UserRole::Owner);

        $this->get('/admin')->assertNotFound();
        $this->get('/admin/tenants')->assertNotFound();
    }

    public function test_staff_must_turn_on_two_factor_first(): void
    {
        config(['yardrunner.back_office.require_two_factor' => true]);
        $this->signInAsStaff(twoFactor: false);

        $this->get('/admin')->assertRedirect(route('security.edit'));
    }

    public function test_staff_are_kept_out_of_the_yard_screens(): void
    {
        $this->signInAsStaff();

        $this->get(route('dashboard'))->assertRedirect(route('admin.overview'));
        $this->get(route('orders.index'))->assertRedirect(route('admin.overview'));
        $this->post(route('orders.store'), [])->assertForbidden();
    }

    public function test_the_overview_shows_figures_across_tenants(): void
    {
        $busy = $this->tenantWithOrders('Halton Stone', 3, ['monthly_price_cents' => 118_000]);
        $this->tenantWithOrders('Quiet Yard', 1, ['status' => TenantStatus::Trial, 'trial_ends_at' => now()->addDays(3)]);
        $this->signInAsStaff();

        $this->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/overview')
                ->where('kpis.active_tenants', 1)
                ->where('kpis.trials', 1)
                ->where('kpis.trials_ending', 1)
                ->where('kpis.mrr_cents', 118_000)
                ->where('kpis.orders', 4)
                ->where('kpis.sales_cents', 40_000)
                ->where('busiest.0.id', $busy->id)
                ->where('busiest.0.orders', 3)
                ->has('growth', 12)
                ->has('adoption', 6)
                ->where('attention', fn ($items) => collect($items)->contains('title', 'Trial ends '.now()->addDays(3)->format('M j'))));
    }

    public function test_the_tenant_list_filters_by_status_and_search(): void
    {
        $this->tenantWithOrders('Halton Stone', 2);
        $this->tenantWithOrders('Rive-Sud Matériaux', 0, ['status' => TenantStatus::Trial]);
        $this->signInAsStaff();

        $this->get('/admin/tenants')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/tenants/index')
                ->has('tenants.data', 2)
                ->where('counts.all', 2)
                ->where('counts.trial', 1));

        $this->get('/admin/tenants?status=trial')
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'Rive-Sud Matériaux'));

        $this->get('/admin/tenants?search=halton')
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants.data', 1)
                ->where('tenants.data.0.orders', 2));
    }

    public function test_staff_can_set_up_a_tenant_and_invite_the_owner(): void
    {
        Notification::fake();
        $this->signInAsStaff();

        $this->get('/admin/tenants/create')
            ->assertInertia(fn (Assert $page) => $page->where('defaultTrialEnds', now('America/Toronto')->addDays(13)->toDateString()));

        $this->post('/admin/tenants', [
            'name' => 'Niagara Hardscape Depot',
            'owner_name' => 'Dana Pereira',
            'owner_email' => 'dana@example.com',
            'trial_ends_at' => now()->addDays(30)->toDateString(),
            'timezone' => 'America/Toronto',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tenant = Tenant::query()->where('name', 'Niagara Hardscape Depot')->firstOrFail();
        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertSame(now()->addDays(30)->toDateString(), $tenant->trial_ends_at?->toDateString());
        // The base subscription, with no add-ons until they switch them on.
        $this->assertSame(74_900, $tenant->monthly_price_cents);
        $this->assertSame('yard', $tenant->plan);
        $this->assertFalse($tenant->rentals_enabled);

        $owner = User::query()->where('email', 'dana@example.com')->firstOrFail();
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertFalse($owner->isPlatformStaff());
        $this->assertSame(1, DB::table('locations')->where('tenant_id', $tenant->id)->count());

        Notification::assertSentTo($owner, UserInviteMessage::class);
    }

    public function test_staff_changes_are_saved_and_kept_out_of_the_tenants_own_audit_log(): void
    {
        $tenant = $this->tenantWithOrders('Halton Stone', 1);
        $staff = $this->signInAsStaff();
        $extended = now()->addDays(21)->toDateString();

        $this->patch("/admin/tenants/{$tenant->id}", [
            'trial_ends_at' => $extended,
            'staff_notes' => 'Pilot customer.',
        ])->assertSessionHasNoErrors();

        // Plan, price, status and module switches aren't staff's to set any more.
        $this->patch("/admin/tenants/{$tenant->id}", [
            'status' => 'active',
            'plan' => 'yard',
            'monthly_price' => '1.00',
            'modules' => ['rentals' => false],
        ])->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertSame($extended, $tenant->trial_ends_at?->toDateString());
        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertTrue($tenant->rentals_enabled);
        $this->assertSame('Pilot customer.', $tenant->staff_notes);

        $event = AuditEvent::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('subject_type', 'tenant')->firstOrFail();
        $this->assertSame($staff->id, $event->user_id);
        $this->assertSame($extended, $event->changes['trial_ends_at'][1] ?? null);
        $this->assertSame(['…', '…'], $event->changes['staff_notes'] ?? null);

        $this->get("/admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/tenants/show')
                ->where('tenant.status', 'trial')
                ->where('tenant.billing.trial_editable', true)
                ->where('tenant.billing.total_cents', 74_900 + 19_900)
                ->missing('tenant.modules')
                ->where('stats.orders', 1)
                ->where('audit.0.who', $staff->name.' (YardRunner staff)'));

        // The tenant's owner doesn't see staff notes on their account.
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Owner]);
        CurrentTenant::set($tenant);
        $this->actingAs($owner)
            ->get(route('audit.index'))
            ->assertInertia(fn (Assert $page) => $page->where('events.data', fn ($events) => collect($events)->doesntContain('subject_type', 'tenant')));
    }

    public function test_staff_can_suspend_and_restore_a_tenant(): void
    {
        $tenant = $this->tenantWithOrders('Halton Stone', 0);
        $this->signInAsStaff();

        $this->patch("/admin/tenants/{$tenant->id}", ['suspended' => true])->assertSessionHasNoErrors();
        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()?->status);
        $this->assertFalse($tenant->fresh()?->billing()->hasAccess);

        $this->patch("/admin/tenants/{$tenant->id}", ['suspended' => false])->assertSessionHasNoErrors();
        $this->assertSame(TenantStatus::Trial, $tenant->fresh()?->status);
    }

    public function test_a_paid_trial_cant_be_moved(): void
    {
        $tenant = $this->tenantWithOrders('Halton Stone', 0);
        $tenant->forceFill(['stripe_subscription_id' => 'sub_test_1', 'stripe_customer_id' => 'cus_test_1', 'subscription_status' => 'active'])->save();
        $this->signInAsStaff();

        $this->patch("/admin/tenants/{$tenant->id}", ['trial_ends_at' => now()->addMonth()->toDateString()])
            ->assertSessionHasErrors('trial_ends_at');
    }

    public function test_activity_is_recorded_for_weekly_users(): void
    {
        $user = $this->signInToNewTenant(UserRole::Sales);

        $this->get(route('dashboard'))->assertOk();

        $this->assertNotNull($user->fresh()?->last_seen_at);
        $this->assertNotNull(Tenant::query()->find($user->tenant_id)?->last_active_at);
        $this->assertSame(1, DB::table('user_activity_days')->where('user_id', $user->id)->count());

        // A second visit the same day doesn't add another row.
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, DB::table('user_activity_days')->where('user_id', $user->id)->count());
    }

    public function test_reports_and_integration_health_render(): void
    {
        $tenant = $this->tenantWithOrders('Halton Stone', 2);
        CurrentTenant::run($tenant, fn () => Integration::query()->create([
            'provider' => IntegrationProvider::QuickBooks,
            'status' => IntegrationStatus::Error,
            'external_account' => 'Halton Stone Ltd.',
            'last_error' => 'Access token revoked',
        ]));
        WebhookDelivery::record('stripe', null, WebhookDelivery::REJECTED, detail: 'Invalid signature');
        $this->signInAsStaff();

        $this->get('/admin/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/reports')
                ->has('weeks', 12)
                ->has('cohorts', 6)
                ->where('engagement.0.name', 'Halton Stone'));

        $this->get('/admin/integrations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/integrations')
                ->has('hours', 24)
                ->where('issues', fn ($issues) => collect($issues)->contains('integration', 'QuickBooks Online')
                    && collect($issues)->contains('key', 'rejected-stripe')));
    }

    public function test_staff_can_send_failed_quickbooks_records_again(): void
    {
        Queue::fake();
        $tenant = $this->tenantWithOrders('Halton Stone', 1);
        $order = CurrentTenant::run($tenant, fn () => Order::query()->firstOrFail());
        CurrentTenant::run($tenant, fn () => QuickBooksSync::query()->create([
            'syncable_type' => 'order',
            'syncable_id' => $order->id,
            'status' => 'failed',
            'message' => 'Boom',
        ]));
        $this->signInAsStaff();

        $this->post("/admin/integrations/quickbooks/{$tenant->id}/retry")->assertRedirect();

        $this->assertSame('waiting', DB::table('quickbooks_syncs')->value('status'));
        Queue::assertPushed(SendToQuickBooks::class);
    }

    public function test_webhooks_with_a_bad_signature_are_logged(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $this->postJson('/webhooks/stripe', ['id' => 'evt_1'], [
            'Stripe-Signature' => 't=1,v1=nope',
        ])->assertStatus(400);

        $this->assertDatabaseHas('webhook_deliveries', [
            'provider' => 'stripe',
            'outcome' => WebhookDelivery::REJECTED,
        ]);
    }

    public function test_signing_up_starts_a_trial(): void
    {
        $this->skipUnlessFortifyHas('registration');

        $this->post(route('register.store'), [
            'business_name' => 'Kawartha Stone Yard',
            'name' => 'Sam Lee',
            'email' => 'sam@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame(TenantStatus::Trial, Tenant::query()->where('name', 'Kawartha Stone Yard')->value('status'));
    }

    public function test_the_staff_command_adds_and_removes_staff(): void
    {
        $this->artisan('yardrunner:staff:add', ['email' => 'jon@example.com', '--name' => 'Jon'])->assertSuccessful();

        $staff = User::query()->where('email', 'jon@example.com')->firstOrFail();
        $this->assertTrue($staff->isPlatformStaff());
        $this->assertNull($staff->tenant_id);

        $this->artisan('yardrunner:staff:add', ['email' => 'jon@example.com', '--remove' => true])->assertSuccessful();
        $this->assertFalse($staff->fresh()?->isPlatformStaff());

        // Someone who signs in to a yard can't also be staff.
        $tenantUser = User::factory()->create();
        $this->artisan('yardrunner:staff:add', ['email' => $tenantUser->email])->assertFailed();
    }
}
