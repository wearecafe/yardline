<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Jobs\ImportShopifyOrder;
use App\Models\Integration;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Shopify-Hmac-Sha256'))) {
            return response('Invalid signature', 401);
        }

        $integration = Integration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('provider', IntegrationProvider::Shopify->value)
            ->where('external_account', (string) $request->header('X-Shopify-Shop-Domain'))
            ->first();

        if ($integration === null || ! $integration->isActive()) {
            return response('Unknown shop', 200);
        }

        $topic = (string) $request->header('X-Shopify-Topic');

        CurrentTenant::run($integration->tenant_id, function () use ($topic, $integration, $request) {
            if (in_array($topic, ['orders/create', 'orders/paid'], true)) {
                ImportShopifyOrder::dispatch($integration->id, $request->json()->all());
            }
        });

        return response('OK', 200);
    }
}
