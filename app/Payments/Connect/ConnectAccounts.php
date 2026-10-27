<?php

namespace App\Payments\Connect;

use App\Models\Tenant;

/**
 * The connected account each yard takes card payments on. YardRunner
 * creates it (Stripe Connect, no Stripe dashboard); the yard onboards,
 * manages payouts and answers disputes inside YardRunner.
 */
interface ConnectAccounts
{
    /**
     * Create the yard's connected account if it doesn't have one yet.
     */
    public function ensureAccount(Tenant $tenant, ?string $email = null): string;

    /**
     * A short-lived client secret for Stripe's embedded components, or null
     * when there's no Stripe (local development, the demo yard).
     */
    public function sessionSecret(Tenant $tenant): ?string;

    /**
     * Read the account from Stripe and save its status on the tenant.
     */
    public function refresh(Tenant $tenant): void;
}
