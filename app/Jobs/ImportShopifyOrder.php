<?php

namespace App\Jobs;

use App\Models\Integration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Brings an order placed in Shopify into Yardline.
 *
 * TODO (next milestone): match line SKUs to products, match or create the
 * customer by email, create a confirmed prepaid order at the Shopify-charged
 * prices, record the Shopify payment, and store an ExternalReference so
 * re-delivered webhooks are ignored.
 */
class ImportShopifyOrder implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $integrationId,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $integration = Integration::query()->find($this->integrationId);

        Log::info('Shopify order received (import not built yet)', [
            'integration_id' => $integration?->id,
            'shopify_order_id' => $this->payload['admin_graphql_api_id'] ?? $this->payload['id'] ?? null,
            'name' => $this->payload['name'] ?? null,
        ]);
    }
}
