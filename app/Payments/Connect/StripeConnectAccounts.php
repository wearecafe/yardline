<?php

namespace App\Payments\Connect;

use App\Models\Tenant;
use App\Support\StripeGateway;
use Illuminate\Support\Facades\Cache;

/**
 * Connected accounts on YardRunner's Stripe platform. Created with no
 * Stripe dashboard: Stripe keeps the risk and collects verification
 * details, the yard pays Stripe's fees directly, and everything the yard
 * sees happens in YardRunner through Stripe's embedded components.
 */
class StripeConnectAccounts implements ConnectAccounts
{
    /** Lumber and building materials stores. */
    private const MCC = '5211';

    public function __construct(private readonly StripeGateway $stripe) {}

    public function ensureAccount(Tenant $tenant, ?string $email = null): string
    {
        if ($tenant->stripe_account_id !== null) {
            return $tenant->stripe_account_id;
        }

        // Two owners clicking at once must not make two accounts.
        return Cache::lock("stripe-connect-account:{$tenant->id}", 30)->block(10, function () use ($tenant, $email) {
            $tenant->refresh();

            if ($tenant->stripe_account_id !== null) {
                return $tenant->stripe_account_id;
            }

            $account = $this->stripe->client()->accounts->create(array_filter([
                'country' => (string) config('yardline.payments.country', 'CA'),
                'email' => $email,
                'default_currency' => strtolower($tenant->currency),
                'controller' => [
                    'stripe_dashboard' => ['type' => 'none'],
                    'fees' => ['payer' => 'account'],
                    'losses' => ['payments' => 'stripe'],
                    'requirement_collection' => 'stripe',
                ],
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_profile' => [
                    'name' => $tenant->name,
                    'mcc' => self::MCC,
                    'product_description' => 'Building and landscape supplies, delivery and equipment rental.',
                ],
                'metadata' => ['yardrunner_tenant_id' => (string) $tenant->id],
            ]), ['idempotency_key' => 'yardrunner-connect-account-'.md5((string) config('app.url')).'-'.$tenant->id]);

            $tenant->forceFill(['stripe_account_id' => $account->id])->save();
            AccountSync::apply($tenant, $account->toArray());

            return $account->id;
        });
    }

    public function sessionSecret(Tenant $tenant): ?string
    {
        $account = $tenant->stripe_account_id ?? throw PaymentsNotSetUp::noAccount();

        $session = $this->stripe->client()->accountSessions->create([
            'account' => $account,
            'components' => [
                'account_onboarding' => ['enabled' => true, 'features' => ['external_account_collection' => true]],
                'account_management' => ['enabled' => true, 'features' => ['external_account_collection' => true]],
                'notification_banner' => ['enabled' => true, 'features' => ['external_account_collection' => true]],
                'documents' => ['enabled' => true],
                'payouts' => ['enabled' => true, 'features' => [
                    'standard_payouts' => true,
                    'instant_payouts' => true,
                    'edit_payout_schedule' => true,
                    'external_account_collection' => true,
                ]],
                // Disputes are answered here. Refunds and captures stay in
                // YardRunner so its records and QuickBooks stay right.
                'payments' => ['enabled' => true, 'features' => [
                    'refund_management' => false,
                    'capture_payments' => false,
                    'dispute_management' => true,
                    'destination_on_behalf_of_charge_management' => false,
                ]],
                'tax_settings' => ['enabled' => true],
                'tax_registrations' => ['enabled' => true],
            ],
        ]);

        return (string) $session->client_secret;
    }

    public function refresh(Tenant $tenant): void
    {
        if ($tenant->stripe_account_id === null) {
            return;
        }

        $account = $this->stripe->client()->accounts->retrieve($tenant->stripe_account_id);
        AccountSync::apply($tenant, $account->toArray());
    }
}
