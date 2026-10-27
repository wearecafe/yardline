<?php

namespace App\Payments\Connect;

use App\Models\Tenant;

/**
 * Local development and the demo yard: an account ID with no Stripe behind
 * it. Setup is simulated on the Payments page.
 */
class FakeConnectAccounts implements ConnectAccounts
{
    public function ensureAccount(Tenant $tenant, ?string $email = null): string
    {
        if ($tenant->stripe_account_id === null) {
            $tenant->forceFill(['stripe_account_id' => 'acct_fake_'.$tenant->id])->save();
        }

        return (string) $tenant->stripe_account_id;
    }

    public function sessionSecret(Tenant $tenant): ?string
    {
        return null;
    }

    public function refresh(Tenant $tenant): void {}

    /**
     * Pretend the yard finished onboarding.
     */
    public function complete(Tenant $tenant): void
    {
        $this->ensureAccount($tenant);

        AccountSync::apply($tenant, [
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => ['currently_due' => [], 'past_due' => []],
        ]);
    }
}
