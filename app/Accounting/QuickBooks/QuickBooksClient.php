<?php

namespace App\Accounting\QuickBooks;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls the QuickBooks Online Accounting API for one connected company,
 * refreshing the access token when it's about to run out.
 */
class QuickBooksClient
{
    public function __construct(private Integration $integration, private QuickBooksOAuth $oauth) {}

    public static function for(Integration $integration): self
    {
        return new self($integration, app(QuickBooksOAuth::class));
    }

    public static function baseUrl(): string
    {
        return config('services.quickbooks.environment') === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->url($path)));
    }

    /**
     * Runs a QuickBooks query ("select * from Account where Active = true")
     * and returns the rows of the entity it selects.
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $sql): array
    {
        $body = $this->send(fn (PendingRequest $http) => $http->get($this->url('query'), ['query' => $sql]));
        $result = $body['QueryResponse'] ?? [];

        foreach ($result as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * Creates or updates an entity ("invoice", "customer"…).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $entity, array $payload, ?string $operation = null): array
    {
        $path = strtolower($entity).($operation ? "?operation={$operation}" : '');

        return $this->send(fn (PendingRequest $http) => $http->post($this->url($path), $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function companyInfo(): array
    {
        $realm = $this->realm();
        $body = $this->get("companyinfo/{$realm}");

        return is_array($body['CompanyInfo'] ?? null) ? $body['CompanyInfo'] : [];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        if ($this->accessExpiresSoon()) {
            $this->oauth->refresh($this->integration);
        }

        $response = $call($this->http());

        if ($response->status() === 401) {
            $this->oauth->refresh($this->integration);
            $response = $call($this->http());
        }

        if (! $response->successful()) {
            throw QuickBooksException::fromResponse($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) ($this->integration->credentials['access_token'] ?? ''))
            ->acceptJson()
            ->asJson()
            ->withQueryParameters(['minorversion' => (string) config('services.quickbooks.minor_version', '75')])
            ->timeout(30);
    }

    private function url(string $path): string
    {
        return self::baseUrl().'/v3/company/'.$this->realm().'/'.ltrim($path, '/');
    }

    private function realm(): string
    {
        return (string) $this->integration->external_account;
    }

    private function accessExpiresSoon(): bool
    {
        $at = $this->integration->credentials['access_expires_at'] ?? null;

        return ! is_string($at) || CarbonImmutable::parse($at)->subMinute()->isPast();
    }
}
