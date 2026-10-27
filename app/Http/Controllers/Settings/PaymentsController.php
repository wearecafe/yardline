<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Payments\Connect\AccountStatus;
use App\Payments\Connect\ConnectAccounts;
use App\Payments\Connect\FakeConnectAccounts;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings → Payments: the yard's card payments, run by YardRunner on a
 * connected account (Stripe Connect). Owners and admins set it up, manage
 * bank details and payouts, answer disputes and add tax registrations
 * here, in Stripe's embedded components; the yard never signs in to
 * Stripe.
 */
class PaymentsController extends Controller
{
    public function edit(ConnectAccounts $accounts): Response
    {
        $tenant = $this->tenant();

        return Inertia::render('settings/payments', [
            'status' => AccountStatus::for($tenant)->toArray(),
            'has_account' => $tenant->stripe_account_id !== null,
            'charges_enabled' => $tenant->stripe_charges_enabled,
            'payouts_enabled' => $tenant->stripe_payouts_enabled,
            'details_submitted' => $tenant->stripe_details_submitted,
            'simulated' => $accounts instanceof FakeConnectAccounts,
            'demo' => Demo::isCurrentTenant(),
            'publishable_key' => $accounts instanceof FakeConnectAccounts ? null : config('services.stripe.key'),
            'fee' => [
                'percent' => (float) config('yardline.payments.application_fee.percent', 0),
                'fixed_cents' => (int) config('yardline.payments.application_fee.fixed_cents', 0),
            ],
        ]);
    }

    /**
     * Create the yard's connected account, then onboarding starts on the page.
     */
    public function store(Request $request, ConnectAccounts $accounts): RedirectResponse
    {
        abort_if(Demo::isCurrentTenant(), 403, 'The demo yard can’t set up payments.');

        try {
            $accounts->ensureAccount($this->tenant(), $request->user()?->email);
        } catch (Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Payments couldn’t be set up. Try again in a minute.']);

            return back();
        }

        return back();
    }

    /**
     * A client secret for Stripe's embedded components (it expires within
     * the hour; the page asks again when it does).
     */
    public function session(ConnectAccounts $accounts): JsonResponse
    {
        $tenant = $this->tenant();
        abort_if($tenant->stripe_account_id === null, 409, 'Set up payments first.');

        try {
            $secret = $accounts->sessionSecret($tenant);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Stripe couldn’t be reached. Try again in a minute.'], 502);
        }

        abort_if($secret === null, 404);

        return response()->json(['client_secret' => $secret]);
    }

    /**
     * Read the account's status from Stripe, after the yard leaves onboarding.
     * The account.updated webhook keeps it current otherwise.
     */
    public function refresh(ConnectAccounts $accounts): RedirectResponse
    {
        try {
            $accounts->refresh($this->tenant());
        } catch (Throwable $e) {
            report($e);
        }

        return back();
    }

    /**
     * Local development only: pretend onboarding is finished.
     */
    public function simulate(ConnectAccounts $accounts): RedirectResponse
    {
        abort_unless($accounts instanceof FakeConnectAccounts, 404);
        abort_if(Demo::isCurrentTenant(), 403);

        $accounts->complete($this->tenant());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Card payments set up (simulated).']);

        return back();
    }

    private function tenant(): Tenant
    {
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        return $tenant;
    }
}
