<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Jobs\ImportShopifyOrder;
use App\Models\Integration;
use App\Models\WebhookDelivery;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Receives Shopify webhooks (orders/create, orders/paid). Verifies the HMAC,
 * finds the tenant by shop domain and queues the work.
 */
class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.shopify.webhook_secret');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        $topic = (string) $request->header('X-Shopify-Topic');

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Shopify-Hmac-Sha256'))) {
            WebhookDelivery::record('shopify', $topic, WebhookDelivery::REJECTED, detail: 'Invalid signature');

            return response('Invalid signature', 401);
        }

        $shop = (string) $request->header('X-Shopify-Shop-Domain');

        $integration = Integration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('provider', IntegrationProvider::Shopify->value)
            ->where('external_account', $shop)
            ->first();

        if ($integration === null) {
            WebhookDelivery::record('shopify', $topic, WebhookDelivery::IGNORED, detail: "Unknown shop {$shop}");

            return response('Unknown shop', 200);
        }

        if (! $integration->isActive()) {
            WebhookDelivery::record('shopify', $topic, WebhookDelivery::IGNORED, $integration->tenant_id, "Integration {$integration->status->value}");

            return response('Unknown shop', 200);
        }

        if (! in_array($topic, ['orders/create', 'orders/paid'], true)) {
            WebhookDelivery::record('shopify', $topic, WebhookDelivery::IGNORED, $integration->tenant_id);

            return response('OK', 200);
        }

        try {
            CurrentTenant::run($integration->tenant_id, function () use ($integration, $request) {
                ImportShopifyOrder::dispatch($integration->id, $request->json()->all());
            });
        } catch (Throwable $e) {
            WebhookDelivery::record('shopify', $topic, WebhookDelivery::FAILED, $integration->tenant_id, $e->getMessage());

            throw $e;
        }

        WebhookDelivery::record('shopify', $topic, WebhookDelivery::ACCEPTED, $integration->tenant_id);

        return response('OK', 200);
    }
}
