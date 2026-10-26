<?php

namespace App\Support;

use App\Tenancy\CurrentTenant;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Builds Stripe clients for the current tenant. When a tenant has a connected
 * Stripe account (Connect), requests are made on that account's behalf.
 */
class StripeGateway
{
    private ?StripeClient $client = null;

    public function __construct(private readonly ?string $secret) {}

    public function client(): StripeClient
    {
        if (blank($this->secret)) {
            throw new RuntimeException('STRIPE_SECRET is not set.');
        }

        return $this->client ??= new StripeClient($this->secret);
    }

    /**
     * @return array{stripe_account?: string}
     */
    public function requestOptions(): array
    {
        $account = CurrentTenant::get()?->stripe_account_id;

        return $account ? ['stripe_account' => $account] : [];
    }
}
