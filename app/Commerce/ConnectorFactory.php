<?php

namespace App\Commerce;

use App\Commerce\Shopify\ShopifyClient;
use App\Commerce\Shopify\ShopifyConnector;
use App\Enums\IntegrationProvider;
use App\Models\Integration;

class ConnectorFactory
{
    public function for(Integration $integration): CommerceConnector
    {
        return match ($integration->provider) {
            IntegrationProvider::Shopify => new ShopifyConnector(new ShopifyClient(
                shopDomain: (string) $integration->external_account,
                accessToken: (string) ($integration->credentials['access_token'] ?? ''),
                apiVersion: (string) config('yardline.shopify.api_version'),
            )),
            default => throw new \LogicException("{$integration->provider->value} is not a storefront."),
        };
    }
}
