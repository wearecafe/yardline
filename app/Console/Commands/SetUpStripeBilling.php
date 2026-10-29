<?php

namespace App\Console\Commands;

use App\Billing\Gateways\StripeBillingGateway;
use App\Billing\Plan;
use App\Http\Controllers\Webhooks\StripeBillingWebhookController;
use App\Support\StripeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Throwable;

/**
 * Creates YardRunner's subscription products and prices in Stripe from
 * yardrunner.billing.items, found again later by their lookup keys. Safe to
 * run again: it only adds what's missing, and when an amount in config
 * has changed it adds a new price and moves the lookup key to it.
 *
 * With --webhook it also adds the billing webhook endpoint and prints its
 * signing secret.
 */
class SetUpStripeBilling extends Command
{
    protected $signature = 'yardrunner:billing:stripe-setup
        {--webhook= : Also create the billing webhook at this URL (https://<your-domain>/webhooks/stripe/billing)}';

    /** The name it had before the rename, kept so old habits and docs still work. */
    protected $aliases = ['yardline:billing:stripe-setup'];

    protected $description = 'Create the subscription products, prices (and optionally the webhook) in Stripe';

    /** Stripe's tax code for business software as a service. */
    private const TAX_CODE = 'txcd_10103001';

    public function handle(StripeGateway $gateway): int
    {
        try {
            $stripe = $gateway->client();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $currency = (string) config('yardrunner.billing.currency', 'cad');
        $mode = str_starts_with((string) config('services.stripe.secret'), 'sk_live_') ? 'LIVE' : 'test';
        $this->line("Stripe account: {$mode} mode");

        $products = $this->products($stripe);

        foreach (array_keys((array) config('yardrunner.billing.items')) as $key) {
            $key = (string) $key;
            $lookup = Plan::lookupKey($key);
            $amount = Plan::amount($key);
            $name = Plan::name($key);

            $existing = $stripe->prices->all(['lookup_keys' => [$lookup], 'active' => true, 'limit' => 1])->data[0] ?? null;

            if ($existing instanceof Price
                && $existing->unit_amount === $amount
                && $existing->currency === $currency
                && $existing->recurring?->interval === 'month') {
                $this->line("✓ {$name}: {$existing->id} (".$this->money($amount, $currency).') already set up');

                continue;
            }

            $product = $products[$key] ?? $stripe->products->create([
                'name' => $name,
                'tax_code' => self::TAX_CODE,
                'metadata' => ['yardrunner_item' => $key],
                ...(Plan::perUser($key) ? ['unit_label' => 'user'] : []),
            ]);

            $price = $stripe->prices->create([
                'product' => $product->id,
                'currency' => $currency,
                'unit_amount' => $amount,
                'recurring' => ['interval' => 'month', 'usage_type' => 'licensed'],
                'tax_behavior' => 'exclusive',
                'lookup_key' => $lookup,
                'transfer_lookup_key' => true,
                'nickname' => $name.(Plan::perUser($key) ? ' (per user)' : '').' monthly',
            ]);

            $this->info(($existing === null ? '+ Created ' : '↻ New price for ').$name.": {$price->id} (".$this->money($amount, $currency).(Plan::perUser($key) ? ' per user' : '').' a month)');
        }

        Cache::forget(StripeBillingGateway::pricesCacheKey());

        if (filled($url = $this->option('webhook'))) {
            $this->webhook($stripe, (string) $url);
        }

        return self::SUCCESS;
    }

    /**
     * Products this command made before, by item key.
     *
     * @return array<string, Product>
     */
    private function products(StripeClient $stripe): array
    {
        $found = [];

        foreach ($stripe->products->all(['active' => true, 'limit' => 100])->autoPagingIterator() as $product) {
            $key = $product->metadata['yardrunner_item'] ?? null;

            if (is_string($key) && ! isset($found[$key])) {
                $found[$key] = $product;
            }
        }

        return $found;
    }

    private function webhook(StripeClient $stripe, string $url): void
    {
        foreach ($stripe->webhookEndpoints->all(['limit' => 100])->autoPagingIterator() as $endpoint) {
            if ($endpoint->url === $url) {
                $this->warn("A webhook for {$url} already exists ({$endpoint->id}). Its signing secret is on the endpoint in the Stripe dashboard.");

                return;
            }
        }

        $endpoint = $stripe->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => StripeBillingWebhookController::EVENTS,
            'description' => 'YardRunner subscriptions',
        ]);

        $this->info("+ Created webhook {$endpoint->id}");
        $this->line('Set this in the environment:');
        $this->line("STRIPE_BILLING_WEBHOOK_SECRET={$endpoint->secret}");
    }

    private function money(int $cents, string $currency): string
    {
        return '$'.number_format($cents / 100, 2).' '.strtoupper($currency);
    }
}
