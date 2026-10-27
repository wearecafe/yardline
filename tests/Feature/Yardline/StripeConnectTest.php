<?php

namespace Tests\Feature\Yardline;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Payments\Connect\AccountStatus;
use App\Payments\Connect\FakeConnectAccounts;
use App\Payments\Connect\PaymentsNotSetUp;
use App\Support\StripeGateway;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Card payments on a connected account YardRunner creates for each yard
 * (Stripe Connect, white-labelled): setup, the no-fallback rule, the
 * application fee and the account.updated webhook.
 */
class StripeConnectTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECT_SECRET = 'whsec_test_connect';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'yardline.payments.driver' => 'fake',
            'yardline.payments.application_fee' => ['percent' => 0.5, 'fixed_cents' => 0],
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
            'services.stripe.webhook_secret' => 'whsec_test_platform',
        ]);
    }

    public function test_owners_see_the_payments_page(): void
    {
        $this->signInToNewTenant();

        $this->get(route('payments.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/payments')
                ->where('status.state', AccountStatus::NOT_STARTED)
                ->where('has_account', false)
                ->where('simulated', true)
                ->where('fee.percent', 0.5));
    }

    public function test_sales_cant_open_payments(): void
    {
        $this->signInToNewTenant(UserRole::Sales);

        $this->get(route('payments.edit'))->assertForbidden();
        $this->post(route('payments.store'))->assertForbidden();
        $this->post(route('payments.session'))->assertForbidden();
    }

    public function test_setting_up_creates_an_account_then_onboarding_turns_charges_on(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();

        $this->post(route('payments.store'))->assertRedirect();
        $tenant->refresh();
        $this->assertSame('acct_fake_'.$tenant->id, $tenant->stripe_account_id);
        $this->assertFalse($tenant->acceptsCards());
        $this->assertSame(AccountStatus::ONBOARDING, AccountStatus::for($tenant)->state);

        // Setting up again keeps the same account.
        $this->post(route('payments.store'));
        $this->assertSame('acct_fake_'.$tenant->id, $tenant->fresh()?->stripe_account_id);

        $this->post(route('payments.simulate'))->assertRedirect();
        $tenant->refresh();
        $this->assertTrue($tenant->acceptsCards());
        $this->assertTrue($tenant->stripe_payouts_enabled);
        $this->assertSame(AccountStatus::ACTIVE, AccountStatus::for($tenant)->state);
    }

    public function test_simulating_setup_is_only_for_local_development(): void
    {
        $this->signInToNewTenant();
        config(['yardline.payments.driver' => 'stripe', 'services.stripe.secret' => 'sk_test_123']);

        $this->post(route('payments.simulate'))->assertNotFound();
    }

    public function test_the_session_endpoint_needs_an_account(): void
    {
        $this->signInToNewTenant();

        $this->postJson(route('payments.session'))->assertStatus(409);

        app(FakeConnectAccounts::class)->ensureAccount(CurrentTenant::get());

        // No Stripe locally, so there's no session to hand out.
        $this->postJson(route('payments.session'))->assertNotFound();
    }

    public function test_stripe_requests_never_fall_back_to_the_platform_account(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        $stripe = new StripeGateway('sk_test_123');

        try {
            $stripe->requestOptions();
            $this->fail('Requests went to the platform account.');
        } catch (PaymentsNotSetUp) {
            // expected
        }

        $tenant->forceFill(['stripe_account_id' => 'acct_123'])->save();
        $this->assertSame(['stripe_account' => 'acct_123'], $stripe->requestOptions());

        // Refunds and lookups work, but new charges wait for onboarding.
        $this->expectException(PaymentsNotSetUp::class);
        $stripe->chargeOptions();
    }

    public function test_charges_go_to_the_account_once_enabled(): void
    {
        $this->signInToNewTenant();
        CurrentTenant::get()->forceFill(['stripe_account_id' => 'acct_123', 'stripe_charges_enabled' => true])->save();

        $this->assertSame(['stripe_account' => 'acct_123'], (new StripeGateway('sk_test_123'))->chargeOptions());
    }

    public function test_application_fee(): void
    {
        $this->assertSame(50, StripeGateway::applicationFee(10_000));
        $this->assertSame(0, StripeGateway::applicationFee(0));
        $this->assertSame(1, StripeGateway::applicationFee(150)); // 0.75¢ rounds to 1

        config(['yardline.payments.application_fee' => ['percent' => 1, 'fixed_cents' => 25]]);
        $this->assertSame(125, StripeGateway::applicationFee(10_000));
        $this->assertSame(20, StripeGateway::applicationFee(20)); // never more than the payment
    }

    public function test_account_updated_webhook_syncs_the_yard(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Stone Yard']);
        $tenant->forceFill(['stripe_account_id' => 'acct_abc'])->save();

        $this->connectWebhook('account.updated', 'acct_abc', [
            'id' => 'acct_abc',
            'object' => 'account',
            'charges_enabled' => true,
            'payouts_enabled' => false,
            'details_submitted' => true,
            'requirements' => [
                'currently_due' => ['external_account'],
                'past_due' => [],
                'disabled_reason' => null,
                'current_deadline' => null,
            ],
        ])->assertOk();

        $tenant->refresh();
        $this->assertTrue($tenant->stripe_charges_enabled);
        $this->assertFalse($tenant->stripe_payouts_enabled);
        $this->assertTrue($tenant->stripe_details_submitted);
        $this->assertSame(['external_account'], $tenant->stripe_requirements['currently_due'] ?? null);
        $this->assertNotNull($tenant->stripe_account_synced_at);

        $status = AccountStatus::for($tenant);
        $this->assertSame(AccountStatus::ACTION_REQUIRED, $status->state);
        $this->assertSame(['external_account'], $status->due);

        $this->assertDatabaseHas('webhook_deliveries', [
            'provider' => 'stripe',
            'topic' => 'account.updated',
            'outcome' => WebhookDelivery::ACCEPTED,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_account_updated_for_an_unknown_account_is_ignored(): void
    {
        $this->connectWebhook('account.updated', 'acct_nobody', [
            'id' => 'acct_nobody',
            'object' => 'account',
            'charges_enabled' => true,
        ])->assertOk();

        $this->assertDatabaseHas('webhook_deliveries', [
            'provider' => 'stripe',
            'outcome' => WebhookDelivery::IGNORED,
        ]);
    }

    public function test_paused_charges_show_as_paused(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->forceFill([
            'stripe_account_id' => 'acct_paused',
            'stripe_details_submitted' => true,
            'stripe_charges_enabled' => false,
            'stripe_requirements' => ['past_due' => ['individual.verification.document'], 'currently_due' => []],
        ])->save();

        $status = AccountStatus::for($tenant);
        $this->assertSame(AccountStatus::PAUSED, $status->state);
        $this->assertSame(['individual.verification.document'], $status->due);
    }

    public function test_business_settings_no_longer_take_an_account_id(): void
    {
        $this->signInToNewTenant();

        $this->patch(route('business.update'), [
            'name' => 'Stone Yard',
            'timezone' => 'America/Toronto',
            'stripe_account_id' => 'acct_someone_elses',
        ])->assertSessionHasNoErrors();

        $this->assertNull(CurrentTenant::get()?->fresh()?->stripe_account_id);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function connectWebhook(string $type, string $account, array $object): TestResponse
    {
        $payload = (string) json_encode([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'account' => $account,
            'created' => time(),
            'data' => ['object' => $object],
        ]);
        $time = time();
        $signature = hash_hmac('sha256', "{$time}.{$payload}", self::CONNECT_SECRET);

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
