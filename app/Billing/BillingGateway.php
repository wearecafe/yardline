<?php

namespace App\Billing;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Where tenants' subscriptions live: Stripe Billing in production, or a
 * local stand-in for development and tests.
 */
interface BillingGateway
{
    /**
     * Where to send an owner or admin to add their payment details and
     * start the subscription. The trial carries over if it's still running.
     */
    public function checkoutUrl(Tenant $tenant, User $user, string $successUrl, string $cancelUrl): string;

    /**
     * Stripe's customer portal: card, billing address, invoices, cancelling.
     */
    public function portalUrl(Tenant $tenant, string $returnUrl): string;

    /**
     * Make the subscription's items match what the tenant has switched on,
     * prorating the difference onto the next invoice.
     */
    public function syncItems(Tenant $tenant, Plan $plan): void;

    /**
     * Move the end of a subscription's free trial (staff extending it).
     */
    public function updateTrialEnd(Tenant $tenant, CarbonImmutable $endsAt): void;
}
