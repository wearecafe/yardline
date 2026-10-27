<?php

namespace App\Billing\Gateways;

use App\Billing\BillingGateway;
use App\Billing\BillingState;
use App\Billing\ItemChanges;
use App\Billing\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Tenants' subscriptions in Stripe Billing, on YardRunner's own Stripe
 * account. Never sent on a yard's connected account: requests here don't
 * use StripeGateway::requestOptions().
 */
class StripeBillingGateway implements BillingGateway
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function checkoutUrl(Tenant $tenant, User $user, string $successUrl, string $cancelUrl): string
    {
        $prices = $this->prices();
        $lineItems = [];

        foreach (Plan::for($tenant)->quantities() as $key => $quantity) {
            $lineItems[] = ['price' => $prices[$key], 'quantity' => $quantity];
        }

        $subscription = [
            'metadata' => ['tenant_id' => (string) $tenant->id],
            'description' => "YardRunner for {$tenant->name}",
        ];

        if (BillingState::for($tenant)->inTrial()) {
            // Checkout won't take a trial ending in under 48 hours, so a
            // tenant subscribing on their last day or two gets a little extra.
            $ends = BillingState::trialEndsAtMoment($tenant);
            $earliest = CarbonImmutable::now()->addHours(49);
            $subscription['trial_end'] = ($ends === null || $ends->lessThan($earliest) ? $earliest : $ends)->getTimestamp();
        }

        $params = [
            'mode' => 'subscription',
            'customer' => $this->customer($tenant, $user),
            'client_reference_id' => (string) $tenant->id,
            'line_items' => $lineItems,
            'subscription_data' => $subscription,
            'payment_method_collection' => 'always',
            'billing_address_collection' => 'required',
            'customer_update' => ['address' => 'auto', 'name' => 'auto'],
            'tax_id_collection' => ['enabled' => true],
            'allow_promotion_codes' => true,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if (config('yardline.billing.automatic_tax')) {
            $params['automatic_tax'] = ['enabled' => true];
        }

        $session = $this->client()->checkout->sessions->create($params);

        return (string) $session->url;
    }

    public function portalUrl(Tenant $tenant, string $returnUrl): string
    {
        if ($tenant->stripe_customer_id === null) {
            throw new RuntimeException('This tenant has no Stripe customer yet.');
        }

        $params = ['customer' => $tenant->stripe_customer_id, 'return_url' => $returnUrl];

        if (filled($configuration = config('yardline.billing.portal_configuration'))) {
            $params['configuration'] = $configuration;
        }

        return (string) $this->client()->billingPortal->sessions->create($params)->url;
    }

    public function syncItems(Tenant $tenant, Plan $plan): void
    {
        if ($tenant->stripe_subscription_id === null) {
            return;
        }

        $subscription = $this->client()->subscriptions->retrieve($tenant->stripe_subscription_id);

        if (! in_array($subscription->status, BillingState::WORKING, true)) {
            return;
        }

        $current = [];

        foreach ($subscription->items->data as $item) {
            $current[] = [
                'id' => (string) $item->id,
                'price' => (string) $item->price->id,
                'key' => Plan::keyForLookup($item->price->lookup_key),
                'quantity' => (int) $item->quantity,
            ];
        }

        $changes = ItemChanges::for($current, $plan->quantities(), $this->prices());

        if ($changes === []) {
            return;
        }

        // The webhook that follows copies the result back onto the tenant.
        $this->client()->subscriptions->update($subscription->id, [
            'items' => $changes,
            'proration_behavior' => 'create_prorations',
        ]);
    }

    public function updateTrialEnd(Tenant $tenant, CarbonImmutable $endsAt): void
    {
        if ($tenant->stripe_subscription_id === null || $tenant->subscription_status !== 'trialing') {
            return;
        }

        $this->client()->subscriptions->update($tenant->stripe_subscription_id, [
            'trial_end' => $endsAt->isFuture() ? $endsAt->getTimestamp() : 'now',
            'proration_behavior' => 'none',
        ]);
    }

    /**
     * The tenant's customer in Stripe, created the first time they subscribe.
     */
    private function customer(Tenant $tenant, User $user): string
    {
        if ($tenant->stripe_customer_id !== null) {
            return $tenant->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'name' => $tenant->name,
            'email' => $user->email,
            'metadata' => ['tenant_id' => (string) $tenant->id, 'tenant_slug' => $tenant->slug],
            'preferred_locales' => ['en-CA'],
        ], ['idempotency_key' => "yardrunner-tenant-{$tenant->id}-customer"]);

        $tenant->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /**
     * Stripe price IDs by item key, found by lookup key.
     *
     * @return array<string, string>
     */
    public function prices(): array
    {
        $keys = self::lookupKeys();

        /** @var array<string, string> $prices */
        $prices = Cache::remember(self::pricesCacheKey(), now()->addMinutes(10), function () use ($keys) {
            $found = [];

            foreach ($this->client()->prices->all(['lookup_keys' => array_values($keys), 'active' => true, 'limit' => 10])->data as $price) {
                $key = array_search($price->lookup_key, $keys, true);

                if (is_string($key)) {
                    $found[$key] = $price->id;
                }
            }

            $missing = array_diff_key($keys, $found);

            if ($missing !== []) {
                throw new RuntimeException('Stripe has no active price for '.implode(', ', $missing).'. Run php artisan yardline:billing:stripe-setup.');
            }

            return $found;
        });

        return $prices;
    }

    public static function pricesCacheKey(): string
    {
        return 'yardline:billing:prices:'.md5(implode(',', self::lookupKeys()));
    }

    /**
     * @return array<string, string> lookup key by item key
     */
    private static function lookupKeys(): array
    {
        $keys = [];

        foreach (array_keys((array) config('yardline.billing.items')) as $key) {
            $keys[(string) $key] = Plan::lookupKey((string) $key);
        }

        return $keys;
    }

    private function client(): StripeClient
    {
        return $this->stripe->client();
    }
}
