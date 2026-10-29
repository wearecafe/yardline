<?php

namespace Tests\Feature\Yardrunner;

use App\Access\UserAccounts;
use App\Billing\BillingState;
use App\Billing\ItemChanges;
use App\Billing\Plan;
use App\Billing\Trial;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Jobs\SyncSubscription;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Tenancy\CurrentTenant;
use App\Time\TimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tenants' YardRunner subscription: the 14-day trial, add-ons switched on
 * by the tenant, locking out when unpaid, and Stripe's webhooks. Uses the
 * fake billing driver (the default outside production).
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_billing';

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardrunner.billing.driver' => 'fake', 'yardrunner.billing.webhook_secret' => self::SECRET]);
    }

    private function tenant(): Tenant
    {
        $tenant = CurrentTenant::get();
        $this->assertNotNull($tenant);

        return $tenant;
    }

    public function test_signing_up_starts_a_14_day_trial_with_add_ons_off(): void
    {
        $this->post(route('register.store'), [
            'business_name' => 'Niagara Hardscape Depot',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $tenant = Tenant::query()->where('name', 'Niagara Hardscape Depot')->firstOrFail();
        $state = $tenant->billing();

        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertSame(14, $state->trialDaysLeft);
        $this->assertTrue($state->hasAccess);
        $this->assertFalse($tenant->rentals_enabled);
        $this->assertSame('yard', $tenant->plan);
        $this->assertSame(74_900, $tenant->monthly_price_cents);
    }

    public function test_the_trial_runs_through_its_last_day(): void
    {
        $tenant = Tenant::factory()->create(['trial_ends_at' => '2026-11-09']);

        $lastDay = BillingState::for($tenant, CarbonImmutable::parse('2026-11-09 23:30', 'America/Toronto'));
        $this->assertTrue($lastDay->hasAccess);
        $this->assertSame(1, $lastDay->trialDaysLeft);

        $after = BillingState::for($tenant, CarbonImmutable::parse('2026-11-10 00:01', 'America/Toronto'));
        $this->assertFalse($after->hasAccess);
        $this->assertSame(TenantStatus::Expired, $after->status);
        $this->assertSame(
            CarbonImmutable::parse('2026-11-10 00:00', 'America/Toronto')->getTimestamp(),
            BillingState::trialEndsAtMoment($tenant)?->getTimestamp(),
        );
    }

    public function test_add_ons_can_be_switched_on_free_during_the_trial(): void
    {
        $this->signInToNewTenant();
        $tenant = $this->tenant();
        $tenant->forceFill(['rentals_enabled' => false, 'trial_ends_at' => Trial::endsOn()])->save();

        // Off: managers are sent to Billing, the public booking page is gone.
        $this->get(route('fleet.index'))->assertRedirect(route('billing.edit'));
        $this->get(route('book.show', $tenant->slug))->assertNotFound();
        $this->get(route('time.board'))->assertRedirect(route('time-settings.edit'));

        $this->put(route('billing.add-ons'), ['rentals' => true, 'time' => true])->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertTrue($tenant->rentals_enabled);
        $this->assertTrue(TimeSettings::for($tenant)->enabled);
        $this->assertSame('yard_rentals', $tenant->plan);
        // The owner is set up as an employee but, being salaried, isn't tracked.
        $this->assertSame(0, Plan::for($tenant)->seats);
        $this->assertNull($tenant->stripe_subscription_id);

        $this->get(route('fleet.index'))->assertOk();
        $this->get(route('time.board'))->assertOk();

        $this->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/billing')
                ->where('billing.status', 'trial')
                ->where('billing.trial_days_left', 14)
                ->where('lines.1.on', true)
                ->where('total_cents', 74_900 + 19_900));
    }

    public function test_sales_and_drivers_cant_change_billing(): void
    {
        $this->signInToNewTenant(UserRole::Sales);

        $this->get(route('billing.edit'))->assertForbidden();
        $this->put(route('billing.add-ons'), ['rentals' => true])->assertForbidden();
    }

    public function test_time_and_attendance_is_billed_per_tracked_person(): void
    {
        $this->signInToNewTenant();
        $tenant = $this->tenant();
        $this->put(route('billing.add-ons'), ['time' => true]);

        $people = [];

        foreach (['Owen' => true, 'Priya' => true, 'Office cat' => false] as $name => $tracked) {
            $people[$name] = User::factory()->create(['tenant_id' => $tenant->id, 'name' => $name]);
            Employee::query()->create(['user_id' => $people[$name]->id, 'tracks_time' => $tracked]);
        }

        // A user who isn't an employee isn't billed.
        User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bookkeeper']);

        $tenant->refresh();
        $this->assertSame(2, Plan::for($tenant)->seats);
        $this->assertSame(74_900 + 19_900 + 2 * 500, $tenant->monthly_price_cents);

        // Deactivating someone ends their employment and their seat.
        UserAccounts::deactivate($people['Priya']);
        $tenant->refresh();
        $this->assertSame(1, Plan::for($tenant)->seats);
        $this->assertSame(74_900 + 19_900 + 500, $tenant->monthly_price_cents);
    }

    public function test_a_trial_that_ended_locks_everyone_out_except_billing(): void
    {
        $owner = $this->signInToNewTenant();
        $tenant = $this->tenant();
        $tenant->forceFill(['trial_ends_at' => now('America/Toronto')->subDay()->toDateString()])->save();

        $this->get(route('dashboard'))->assertRedirect(route('billing.edit'));
        $this->post(route('orders.store'), [])->assertStatus(402);
        $this->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('billing.status', 'expired')->where('billing.has_access', false));
        $this->get(route('profile.edit'))->assertOk();

        $counter = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Sales]);
        $this->actingAs($counter)->get(route('dashboard'))->assertRedirect(route('billing.paused'));
        $this->get(route('billing.paused'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('billing/paused'));

        $driver = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Driver]);
        $this->actingAs($driver)->get(route('driver.today'))->assertRedirect(route('billing.paused'));
        $this->get(route('billing.paused'))->assertOk();

        // Subscribing after the trial starts billing straight away and lets everyone back in.
        $this->actingAs($owner)->post(route('billing.checkout'))->assertRedirect(route('billing.subscribed'));

        $tenant->refresh();
        $this->assertSame('active', $tenant->subscription_status);
        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->actingAs($counter)->get(route('dashboard'))->assertOk();
    }

    public function test_subscribing_during_the_trial_keeps_the_trial(): void
    {
        $this->signInToNewTenant();
        $tenant = $this->tenant();
        $trialEnds = $tenant->trial_ends_at?->toDateString();

        $this->post(route('billing.checkout'))->assertRedirect(route('billing.subscribed'));
        $this->get(route('billing.subscribed'))->assertRedirect(route('billing.edit'));

        $tenant->refresh();
        $this->assertSame('trialing', $tenant->subscription_status);
        $this->assertSame($trialEnds, $tenant->trial_ends_at?->toDateString());
        $this->assertTrue($tenant->billing()->subscribed);
        $this->assertSame(TenantStatus::Trial, $tenant->status);

        // Already subscribed: checkout goes back to the billing page.
        $this->post(route('billing.checkout'))->assertRedirect(route('billing.edit'));
    }

    public function test_changing_add_ons_when_subscribed_updates_stripe(): void
    {
        $this->signInToNewTenant();
        $tenant = $this->tenant();
        $tenant->forceFill(['stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'subscription_status' => 'active'])->save();
        Queue::fake();

        $this->put(route('billing.add-ons'), ['rentals' => false]);

        Queue::assertPushed(SyncSubscription::class, fn (SyncSubscription $job) => $job->tenantId === $tenant->id);
        $this->assertSame('yard', $tenant->fresh()?->plan);
    }

    public function test_suspended_tenants_are_locked_even_while_paying(): void
    {
        $this->signInToNewTenant();
        $tenant = $this->tenant();
        $tenant->forceFill(['subscription_status' => 'active', 'stripe_subscription_id' => 'sub_1', 'suspended_at' => now()])->save();

        $this->get(route('dashboard'))->assertRedirect(route('billing.paused'));
        $this->get(route('billing.paused'))->assertInertia(fn (Assert $page) => $page->where('suspended', true));
    }

    public function test_webhooks_copy_the_subscription_onto_the_tenant(): void
    {
        $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_9']);

        $this->webhook('customer.subscription.created', $this->subscription($tenant, 'trialing', trialEnd: now()->addDays(20)->getTimestamp()), now()->subMinute()->getTimestamp())->assertOk();

        $tenant->refresh();
        $this->assertSame('sub_9', $tenant->stripe_subscription_id);
        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertSame(3, $tenant->billed_seats);
        $this->assertSame(now('America/Toronto')->addDays(20)->subSecond()->toDateString(), $tenant->trial_ends_at?->toDateString());

        // Past due: still working while Stripe retries.
        $this->webhook('customer.subscription.updated', $this->subscription($tenant, 'past_due'), now()->getTimestamp())->assertOk();
        $tenant->refresh();
        $this->assertSame(TenantStatus::PastDue, $tenant->status);
        $this->assertTrue($tenant->billing()->hasAccess);

        // An older event arriving late doesn't undo it.
        $this->webhook('customer.subscription.updated', $this->subscription($tenant, 'active'), now()->subMinutes(5)->getTimestamp())->assertOk();
        $this->assertSame('past_due', $tenant->fresh()?->subscription_status);

        // Unpaid after the retries: locked.
        $this->webhook('customer.subscription.updated', $this->subscription($tenant, 'unpaid'), now()->addMinute()->getTimestamp())->assertOk();
        $tenant->refresh();
        $this->assertSame(TenantStatus::Suspended, $tenant->status);
        $this->assertFalse($tenant->billing()->hasAccess);

        $this->assertSame(3, WebhookDelivery::query()->where('provider', 'stripe_billing')->where('outcome', WebhookDelivery::ACCEPTED)->count());
    }

    public function test_cancelled_subscriptions_end_access_after_the_trial(): void
    {
        $tenant = Tenant::factory()->trialEnded()->create(['stripe_subscription_id' => 'sub_9', 'subscription_status' => 'active']);

        $this->webhook('customer.subscription.deleted', $this->subscription($tenant, 'canceled'), now()->getTimestamp())->assertOk();

        $this->assertSame(TenantStatus::Cancelled, $tenant->fresh()?->status);
    }

    public function test_webhooks_need_a_valid_signature(): void
    {
        $this->call('POST', route('webhooks.stripe-billing'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=nope', 'CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(400);
    }

    public function test_item_changes_add_update_and_remove(): void
    {
        $prices = ['base' => 'price_base', 'rentals' => 'price_rentals', 'time' => 'price_time'];
        $current = [
            ['id' => 'si_base', 'price' => 'price_base', 'key' => 'base', 'quantity' => 1],
            ['id' => 'si_rentals', 'price' => 'price_rentals', 'key' => 'rentals', 'quantity' => 1],
            ['id' => 'si_time', 'price' => 'price_time_old', 'key' => 'time', 'quantity' => 4],
            ['id' => 'si_custom', 'price' => 'price_setup', 'key' => null, 'quantity' => 1],
        ];

        $this->assertSame([
            ['id' => 'si_rentals', 'deleted' => true],
            ['id' => 'si_time', 'price' => 'price_time', 'quantity' => 6],
        ], ItemChanges::for($current, ['base' => 1, 'time' => 6], $prices));

        $this->assertSame([
            ['price' => 'price_rentals', 'quantity' => 1],
        ], ItemChanges::for([$current[0]], ['base' => 1, 'rentals' => 1], $prices));

        $this->assertSame([], ItemChanges::for([$current[0]], ['base' => 1], $prices));
    }

    public function test_the_home_page_shows_the_price_list(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pricing.currency', 'CAD')
                ->where('pricing.trial_days', 14)
                ->where('pricing.items.0.amount_cents', 74_900)
                ->where('pricing.items.1.amount_cents', 19_900)
                ->where('pricing.items.2.amount_cents', 500)
                ->where('pricing.items.2.per_user', true));
    }

    public function test_the_nightly_refresh_marks_ended_trials(): void
    {
        $tenant = Tenant::factory()->trialEnded()->create(['status' => TenantStatus::Trial]);

        $this->artisan('yardrunner:billing:refresh')->assertSuccessful();

        $this->assertSame(TenantStatus::Expired, $tenant->fresh()?->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(Tenant $tenant, string $status, ?int $trialEnd = null): array
    {
        return [
            'id' => 'sub_9',
            'object' => 'subscription',
            'customer' => 'cus_9',
            'status' => $status,
            'metadata' => ['tenant_id' => (string) $tenant->id],
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'trial_end' => $trialEnd,
            'items' => ['object' => 'list', 'data' => [
                ['id' => 'si_1', 'quantity' => 1, 'current_period_end' => now()->addMonth()->getTimestamp(), 'price' => ['id' => 'price_1', 'lookup_key' => Plan::lookupKey(Plan::BASE)]],
                ['id' => 'si_2', 'quantity' => 3, 'current_period_end' => now()->addMonth()->getTimestamp(), 'price' => ['id' => 'price_2', 'lookup_key' => Plan::lookupKey(Plan::TIME)]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function webhook(string $type, array $object, int $created): TestResponse
    {
        $payload = (string) json_encode([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'created' => $created,
            'data' => ['object' => $object],
        ]);
        $time = time();
        $signature = hash_hmac('sha256', "{$time}.{$payload}", self::SECRET);

        return $this->call('POST', route('webhooks.stripe-billing'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
