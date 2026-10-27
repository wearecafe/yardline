<?php

namespace App\Support;

use App\Models\Tenant;
use App\Payments\Connect\PaymentsNotSetUp;
use App\Tenancy\CurrentTenant;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Builds Stripe clients for the current tenant. Every yard's card payments,
 * customers and tax run on the connected account YardRunner created for it
 * (Stripe Connect); nothing for a yard is ever sent to YardRunner's own
 * account. Subscription billing (what yards pay YardRunner) doesn't use
 * requestOptions() and stays on the platform account.
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
     * Options that send a request to the current yard's connected account.
     * Use for anything that isn't a new charge (refunds, captures, lookups,
     * tax), which must keep working while Stripe has charges paused.
     *
     * @return array{stripe_account: string}
     */
    public function requestOptions(): array
    {
        $account = $this->tenant()->stripe_account_id;

        if ($account === null) {
            throw PaymentsNotSetUp::noAccount();
        }

        return ['stripe_account' => $account];
    }

    /**
     * Options for taking a new card payment: the yard must have finished
     * onboarding and Stripe must have charges switched on.
     *
     * @return array{stripe_account: string}
     */
    public function chargeOptions(): array
    {
        $options = $this->requestOptions();

        if (! $this->tenant()->stripe_charges_enabled) {
            throw PaymentsNotSetUp::chargesDisabled();
        }

        return $options;
    }

    /**
     * YardRunner's application fee on a card payment of this many cents.
     */
    public static function applicationFee(int $cents): int
    {
        if ($cents <= 0) {
            return 0;
        }

        $percent = (float) config('yardline.payments.application_fee.percent', 0);
        $fixed = (int) config('yardline.payments.application_fee.fixed_cents', 0);
        $fee = (int) round($cents * $percent / 100) + $fixed;

        return max(0, min($fee, $cents));
    }

    private function tenant(): Tenant
    {
        $tenant = CurrentTenant::get();

        if ($tenant === null) {
            throw new RuntimeException('No business is selected for this Stripe request.');
        }

        return $tenant;
    }
}
