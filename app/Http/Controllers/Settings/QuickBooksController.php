<?php

namespace App\Http\Controllers\Settings;

use App\Accounting\QuickBooks\AccountMapping;
use App\Accounting\QuickBooks\QuickBooksClient;
use App\Accounting\QuickBooks\QuickBooksOAuth;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Connect a QuickBooks Online company, choose the date sync starts from,
 * and map Yardline's lines and payments to the company's accounts.
 */
class QuickBooksController extends Controller
{
    public function edit(): Response
    {
        $integration = $this->integration();
        $accounts = [];
        $accountsError = null;

        if ($integration !== null) {
            try {
                $accounts = QuickBooksClient::for($integration)->query('select Id, Name, AccountType, FullyQualifiedName from Account where Active = true maxresults 1000');
            } catch (Throwable $e) {
                report($e);
                $accountsError = 'Couldn’t load your QuickBooks accounts: '.$e->getMessage();
            }
        }

        $mapping = $integration !== null ? AccountMapping::guess($accounts, AccountMapping::for($integration)) : [];

        return Inertia::render('settings/quickbooks', [
            'configured' => QuickBooksOAuth::configured(),
            'demo' => Demo::isCurrentTenant(),
            'integration' => $integration === null ? null : [
                'status' => $integration->status->value,
                'company' => $integration->settings['company_name'] ?? null,
                'realm_id' => $integration->external_account,
                'start_date' => $integration->settings['start_date'] ?? null,
                'connected_at' => $integration->created_at?->toIso8601String(),
                'last_error' => $integration->last_error,
                'sandbox' => config('services.quickbooks.environment') !== 'production',
            ],
            'mapping' => $mapping,
            'slots' => collect(AccountMapping::KEYS)->map(fn ($def, $key) => [
                'key' => $key,
                'label' => $def[0],
                'types' => $def[1],
                'default' => $def[2],
            ])->values(),
            'accounts' => collect($accounts)->map(fn (array $a) => [
                'id' => (string) $a['Id'],
                'name' => (string) ($a['FullyQualifiedName'] ?? $a['Name']),
                'type' => (string) ($a['AccountType'] ?? ''),
            ])->sortBy('name')->values(),
            'accountsError' => $accountsError,
        ]);
    }

    public function connect(Request $request, QuickBooksOAuth $oauth): HttpResponse
    {
        abort_if(Demo::isCurrentTenant(), 403, 'The demo yard can’t connect to QuickBooks.');
        abort_unless(QuickBooksOAuth::configured(), 404);

        $state = Str::random(40);
        $request->session()->put('quickbooks_state', $state);

        return Inertia::location($oauth->authorizeUrl($state, route('quickbooks.callback')));
    }

    public function callback(Request $request, QuickBooksOAuth $oauth): RedirectResponse
    {
        $expected = $request->session()->pull('quickbooks_state');

        if ($request->filled('error')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'QuickBooks wasn’t connected.']);

            return to_route('quickbooks.edit');
        }

        abort_unless(is_string($expected) && hash_equals($expected, $request->string('state')->toString()), 403);
        abort_if(Demo::isCurrentTenant(), 403);

        $data = $request->validate(['code' => ['required', 'string'], 'realmId' => ['required', 'string', 'max:40']]);

        $tokens = $oauth->exchange($data['code'], route('quickbooks.callback'));

        $integration = Integration::query()->updateOrCreate(
            ['provider' => IntegrationProvider::QuickBooks->value],
            [
                'status' => IntegrationStatus::Connected,
                'external_account' => $data['realmId'],
                'credentials' => $tokens,
                'last_error' => null,
            ],
        );

        $company = null;

        try {
            $company = QuickBooksClient::for($integration)->companyInfo()['CompanyName'] ?? null;
        } catch (Throwable $e) {
            report($e);
        }

        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $integration->forceFill(['settings' => [
            ...($integration->settings ?? []),
            'company_name' => $company,
            'start_date' => $integration->settings['start_date'] ?? CarbonImmutable::now($tz)->toDateString(),
        ]])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $company ? "Connected to {$company}." : 'QuickBooks connected.']);

        return to_route('quickbooks.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $integration = $this->integration();
        abort_if($integration === null, 404);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'accounts' => ['array'],
            'accounts.*' => ['nullable', 'string', 'max:40'],
        ]);

        $given = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];
        $accounts = array_filter(Arr::only($given, array_keys(AccountMapping::KEYS)));

        $integration->forceFill(['settings' => [
            ...($integration->settings ?? []),
            'start_date' => CarbonImmutable::parse($data['start_date'])->toDateString(),
            'accounts' => $accounts,
        ]])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'QuickBooks settings saved.']);

        return back();
    }

    public function destroy(QuickBooksOAuth $oauth): RedirectResponse
    {
        $integration = $this->integration();

        if ($integration !== null) {
            try {
                $oauth->revoke($integration);
            } catch (Throwable $e) {
                report($e);
            }

            $integration->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'QuickBooks disconnected. Everything already sent stays in QuickBooks.']);

        return to_route('quickbooks.edit');
    }

    private function integration(): ?Integration
    {
        return Integration::query()->where('provider', IntegrationProvider::QuickBooks->value)->first();
    }
}
