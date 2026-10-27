<?php

namespace App\Billing\Gateways;

use App\Billing\Billing;
use App\Billing\BillingGateway;
use App\Billing\BillingState;
use App\Billing\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Subscribes tenants on the spot without Stripe, for local development and
 * tests. Never used in production (see AppServiceProvider).
 */
class FakeBillingGateway implements BillingGateway
{
    public function checkoutUrl(Tenant $tenant, User $user, string $successUrl, string $cancelUrl): string
    {
        $state = BillingState::for($tenant);
        $now = CarbonImmutable::now();
        $trialEnd = $state->inTrial() ? BillingState::trialEndsAtMoment($tenant) : null;

        $tenant->forceFill([
            'stripe_customer_id' => $tenant->stripe_customer_id ?? 'cus_fake_'.Str::lower(Str::random(14)),
        ])->save();

        Billing::apply($tenant, [
            'id' => 'sub_fake_'.Str::lower(Str::random(14)),
            'customer' => $tenant->stripe_customer_id,
            'status' => $trialEnd !== null ? 'trialing' : 'active',
            'trial_end' => $trialEnd?->getTimestamp(),
            'current_period_end' => ($trialEnd ?? $now->addMonth())->getTimestamp(),
            'cancel_at_period_end' => false,
            'items' => ['data' => $this->items(Plan::for($tenant))],
        ], $now->getTimestamp());

        return $successUrl;
    }

    public function portalUrl(Tenant $tenant, string $returnUrl): string
    {
        return $returnUrl;
    }

    public function syncItems(Tenant $tenant, Plan $plan): void
    {
        $tenant->forceFill(['billed_seats' => $plan->quantities()[Plan::TIME] ?? 0])->save();
    }

    public function updateTrialEnd(Tenant $tenant, CarbonImmutable $endsAt): void {}

    /**
     * @return list<array{price: array{lookup_key: string}, quantity: int}>
     */
    private function items(Plan $plan): array
    {
        $items = [];

        foreach ($plan->quantities() as $key => $quantity) {
            $items[] = ['price' => ['lookup_key' => Plan::lookupKey($key)], 'quantity' => $quantity];
        }

        return $items;
    }
}
