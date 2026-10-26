<?php

namespace App\Http\Controllers\Settings;

use App\Commerce\Shopify\ShopifyClient;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Connect a Shopify store with a custom-app Admin API access token.
 * (A public OAuth app comes later if Yardline is offered to other yards.)
 */
class ShopifyController extends Controller
{
    public function edit(): Response
    {
        $integration = $this->integration();

        return Inertia::render('settings/shopify', [
            'integration' => $integration?->only('status', 'external_account', 'last_synced_at', 'last_error'),
            'webhookUrl' => route('webhooks.shopify'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shop_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/'],
            'access_token' => ['required', 'string', 'max:255'],
        ], [
            'shop_domain.regex' => 'Use the store’s myshopify.com domain, e.g. your-yard.myshopify.com.',
        ]);

        $client = new ShopifyClient($data['shop_domain'], $data['access_token'], (string) config('yardline.shopify.api_version'));

        try {
            $client->query('{ shop { name } }');
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'access_token' => 'Shopify rejected these details. Check the domain and Admin API access token.',
            ]);
        }

        Integration::query()->updateOrCreate(
            ['provider' => IntegrationProvider::Shopify->value],
            [
                'status' => IntegrationStatus::Connected,
                'external_account' => $data['shop_domain'],
                'credentials' => ['access_token' => $data['access_token']],
                'last_error' => null,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shopify connected.']);

        return back();
    }

    public function destroy(): RedirectResponse
    {
        $this->integration()?->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shopify disconnected.']);

        return back();
    }

    private function integration(): ?Integration
    {
        return Integration::query()->where('provider', IntegrationProvider::Shopify->value)->first();
    }
}
