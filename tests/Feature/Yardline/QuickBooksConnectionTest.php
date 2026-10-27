<?php

namespace Tests\Feature\Yardline;

use App\Accounting\QuickBooks\QuickBooksClient;
use App\Accounting\QuickBooks\QuickBooksOAuth;
use App\Enums\IntegrationProvider;
use App\Enums\UserRole;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickBooksConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.client_id' => 'client',
            'services.quickbooks.client_secret' => 'secret',
            'services.quickbooks.environment' => 'sandbox',
        ]);

        $this->signInToNewTenant();
    }

    private function fakeIntuit(): void
    {
        Http::fake([
            QuickBooksOAuth::TOKEN_URL => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8_726_400,
            ]),
            QuickBooksOAuth::REVOKE_URL => Http::response([], 200),
            'sandbox-quickbooks.api.intuit.com/v3/company/9130/companyinfo/*' => Http::response(['CompanyInfo' => ['CompanyName' => 'Escarpment Stone Ltd']]),
            'sandbox-quickbooks.api.intuit.com/v3/company/9130/query*' => Http::response(['QueryResponse' => ['Account' => [
                ['Id' => '1', 'Name' => 'Sales', 'AccountType' => 'Income', 'FullyQualifiedName' => 'Sales'],
                ['Id' => '2', 'Name' => 'Pallet deposits held', 'AccountType' => 'Other Current Liability', 'FullyQualifiedName' => 'Pallet deposits held'],
                ['Id' => '3', 'Name' => 'Undeposited Funds', 'AccountType' => 'Other Current Asset', 'FullyQualifiedName' => 'Undeposited Funds'],
                ['Id' => '4', 'Name' => 'Office supplies', 'AccountType' => 'Expense', 'FullyQualifiedName' => 'Office supplies'],
            ]]]),
        ]);
    }

    private function connect(): Integration
    {
        $this->fakeIntuit();

        $response = $this->post(route('quickbooks.connect'));
        $redirect = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        $this->assertStringStartsWith(QuickBooksOAuth::AUTHORIZE_URL, (string) $redirect);
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
        $this->assertSame('com.intuit.quickbooks.accounting', $query['scope']);

        $this->get(route('quickbooks.callback', ['code' => 'abc', 'realmId' => '9130', 'state' => $query['state']]))
            ->assertRedirect(route('quickbooks.edit'));

        return Integration::query()->where('provider', IntegrationProvider::QuickBooks->value)->firstOrFail();
    }

    public function test_owners_connect_a_company(): void
    {
        $integration = $this->connect();

        $this->assertSame('9130', $integration->external_account);
        $this->assertSame('refresh-1', $integration->credentials['refresh_token'] ?? null);
        $this->assertSame('Escarpment Stone Ltd', $integration->settings['company_name'] ?? null);
        $this->assertNotNull($integration->settings['start_date'] ?? null);

        // Tokens are never sent to the page.
        $this->get(route('quickbooks.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('integration.company', 'Escarpment Stone Ltd')
            ->where('mapping.sales', '1')
            ->where('mapping.pallet_deposits', '2')
            ->where('mapping.undeposited', '3')
            ->where('mapping.delivery', null)
            ->has('accounts', 4)
            ->missing('integration.credentials'));
    }

    public function test_a_forged_callback_is_refused(): void
    {
        $this->fakeIntuit();
        $this->post(route('quickbooks.connect'));

        $this->get(route('quickbooks.callback', ['code' => 'abc', 'realmId' => '9130', 'state' => 'not-the-state']))->assertForbidden();
        $this->assertFalse(Integration::query()->exists());
    }

    public function test_mapping_and_start_date_save(): void
    {
        $integration = $this->connect();

        $this->put(route('quickbooks.update'), [
            'start_date' => '2026-11-01',
            'accounts' => ['sales' => '1', 'pallet_deposits' => '2', 'nonsense' => '9'],
        ])->assertSessionHasNoErrors();

        $settings = $integration->refresh()->settings ?? [];
        $this->assertSame('2026-11-01', $settings['start_date']);
        $this->assertSame(['sales' => '1', 'pallet_deposits' => '2'], $settings['accounts']);
    }

    public function test_an_expired_access_token_is_refreshed_before_calling(): void
    {
        $integration = Integration::query()->create([
            'provider' => IntegrationProvider::QuickBooks,
            'external_account' => '9130',
            'credentials' => ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'access_expires_at' => now()->subMinutes(5)->toIso8601String()],
        ]);

        Http::fake([
            QuickBooksOAuth::TOKEN_URL => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
            'sandbox-quickbooks.api.intuit.com/*' => Http::response(['CompanyInfo' => ['CompanyName' => 'Escarpment Stone Ltd']]),
        ]);

        QuickBooksClient::for($integration)->companyInfo();

        $this->assertSame('refresh-2', $integration->refresh()->credentials['refresh_token'] ?? null);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'companyinfo') && $r->hasHeader('Authorization', 'Bearer access-2'));
    }

    public function test_disconnecting_revokes_the_token(): void
    {
        $this->connect();

        $this->delete(route('quickbooks.destroy'))->assertRedirect(route('quickbooks.edit'));

        $this->assertFalse(Integration::query()->exists());
        Http::assertSent(fn (Request $r) => $r->url() === QuickBooksOAuth::REVOKE_URL);
    }

    public function test_only_owners_and_admins_can_connect(): void
    {
        $this->signInToNewTenant(UserRole::Staff);

        $this->get(route('quickbooks.edit'))->assertForbidden();
        $this->post(route('quickbooks.connect'))->assertForbidden();
    }
}
