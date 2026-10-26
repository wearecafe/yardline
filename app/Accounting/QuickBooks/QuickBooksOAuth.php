<?php

namespace App\Accounting\QuickBooks;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Intuit's OAuth 2.0: send the owner to sign in, swap the code for tokens,
 * and keep the access token fresh. Access tokens last an hour; refresh
 * tokens roll forward on every use and lapse after 100 days unused.
 */
class QuickBooksOAuth
{
    public const AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';

    public const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public const REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    public const SCOPE = 'com.intuit.quickbooks.accounting';

    public static function configured(): bool
    {
        return filled(config('services.quickbooks.client_id')) && filled(config('services.quickbooks.client_secret'));
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.quickbooks.client_id'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, access_expires_at: string, refresh_expires_at: string}
     */
    public function exchange(string $code, string $redirectUri): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    /**
     * Swaps the refresh token for a new pair and saves it on the integration.
     */
    public function refresh(Integration $integration): void
    {
        $refresh = (string) ($integration->credentials['refresh_token'] ?? '');

        if ($refresh === '') {
            throw new RuntimeException('QuickBooks is not connected.');
        }

        $integration->forceFill(['credentials' => $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh])])->save();
    }

    public function revoke(Integration $integration): void
    {
        $token = (string) ($integration->credentials['refresh_token'] ?? '');

        if ($token === '') {
            return;
        }

        Http::withBasicAuth((string) config('services.quickbooks.client_id'), (string) config('services.quickbooks.client_secret'))
            ->acceptJson()
            ->asJson()
            ->post(self::REVOKE_URL, ['token' => $token]);
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: string, access_expires_at: string, refresh_expires_at: string}
     */
    private function tokenRequest(array $form): array
    {
        $response = Http::withBasicAuth((string) config('services.quickbooks.client_id'), (string) config('services.quickbooks.client_secret'))
            ->acceptJson()
            ->asForm()
            ->post(self::TOKEN_URL, $form);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('QuickBooks sign-in failed: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        $now = CarbonImmutable::now();

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => (string) $response->json('refresh_token'),
            'access_expires_at' => $now->addSeconds((int) ($response->json('expires_in') ?? 3600))->toIso8601String(),
            'refresh_expires_at' => $now->addSeconds((int) ($response->json('x_refresh_token_expires_in') ?? 8_640_000))->toIso8601String(),
        ];
    }
}
