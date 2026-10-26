<?php

namespace App\Commerce\Shopify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Shopify Admin GraphQL client (custom-app access token).
 */
class ShopifyClient
{
    public function __construct(
        private readonly string $shopDomain,
        private readonly string $accessToken,
        private readonly string $apiVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $response = Http::withHeaders(['X-Shopify-Access-Token' => $this->accessToken])
            ->acceptJson()
            ->timeout(20)
            ->retry(3, 1000, fn ($exception) => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->status() === 429), throw: false)
            ->post("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => $variables,
            ])
            ->throw();

        $body = $response->json();

        if (! empty($body['errors'])) {
            throw new RuntimeException('Shopify: '.json_encode($body['errors']));
        }

        return $body['data'] ?? [];
    }

    /**
     * Run a mutation and fail loudly on userErrors.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function mutate(string $field, string $mutation, array $variables): array
    {
        $result = $this->query($mutation, $variables)[$field] ?? [];

        if (! empty($result['userErrors'])) {
            $messages = collect($result['userErrors'])->pluck('message')->implode('; ');

            throw new RuntimeException("Shopify {$field}: {$messages}");
        }

        return $result;
    }
}
