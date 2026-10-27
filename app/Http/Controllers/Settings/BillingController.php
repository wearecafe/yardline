<?php

namespace App\Http\Controllers\Settings;

use App\Billing\AddOns;
use App\Billing\BillingGateway;
use App\Billing\Plan;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Settings → Billing: the business's YardRunner subscription. Owners and
 * admins switch add-ons on and off (free during the trial), add payment
 * details through Stripe Checkout, and manage their card and invoices in
 * Stripe's customer portal.
 */
class BillingController extends Controller
{
    public function edit(): Response
    {
        $tenant = $this->tenant();
        $state = $tenant->billing();
        $plan = Plan::for($tenant);

        return Inertia::render('settings/billing', [
            'billing' => [
                'status' => $state->status->value,
                'status_label' => $state->status->label(),
                'has_access' => $state->hasAccess,
                'subscribed' => $state->subscribed,
                'exempt' => $state->exempt,
                'trial_days_left' => $state->subscribed ? null : $state->trialDaysLeft,
                'trial_ends_at' => $state->trialEndsAt?->toDateString(),
                'period_ends_at' => $state->periodEndsAt?->toIso8601String(),
                'cancelling' => $state->cancelling,
                'subscription_status' => $tenant->subscription_status,
                'has_customer' => $tenant->stripe_customer_id !== null,
                'suspended' => $tenant->suspended_at !== null,
            ],
            'lines' => $plan->lines(),
            'total_cents' => $plan->monthlyCents(),
            'currency' => strtoupper((string) config('yardline.billing.currency', 'cad')),
            'tracked' => $plan->seats,
        ]);
    }

    /**
     * Switch Rentals or Time & attendance on or off.
     */
    public function addOns(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rentals' => ['sometimes', 'boolean'],
            'time' => ['sometimes', 'boolean'],
        ]);

        $tenant = $this->tenant();
        $subscribed = $tenant->billing()->subscribed;
        $messages = [];

        if (array_key_exists('rentals', $data)) {
            AddOns::setRentals($tenant, (bool) $data['rentals']);
            $messages[] = $data['rentals'] ? 'Rentals is on.' : 'Rentals is off.';
        }

        if (array_key_exists('time', $data)) {
            $added = AddOns::setTime($tenant, (bool) $data['time']);
            $messages[] = $data['time']
                ? 'Time and attendance is on.'.($added > 0 ? " Added {$added} people; give the yard hands a PIN under Team → Employees." : '')
                : 'Time and attendance is off.';
        }

        if ($subscribed && $messages !== []) {
            $messages[] = 'Your next invoice is adjusted for the change.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => implode(' ', $messages)]);

        return back();
    }

    /**
     * Off to Stripe Checkout to add payment details. The trial carries over.
     */
    public function checkout(Request $request, BillingGateway $gateway): HttpResponse
    {
        $tenant = $this->tenant();

        if ($tenant->billing()->subscribed) {
            return to_route('billing.edit');
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $url = $gateway->checkoutUrl($tenant, $user, route('billing.subscribed'), route('billing.edit'));
        } catch (Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'We couldn’t open the payment page. Please try again in a minute.']);

            return back();
        }

        return Inertia::location($url);
    }

    /**
     * Back from Checkout. Stripe's webhook may land a moment after this.
     */
    public function subscribed(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks! Your payment details are saved.']);

        return to_route('billing.edit');
    }

    /**
     * Stripe's customer portal: card, address, invoices, cancelling.
     */
    public function portal(BillingGateway $gateway): HttpResponse
    {
        $tenant = $this->tenant();

        if ($tenant->stripe_customer_id === null) {
            return to_route('billing.edit');
        }

        try {
            $url = $gateway->portalUrl($tenant, route('billing.edit'));
        } catch (Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'We couldn’t open billing details. Please try again in a minute.']);

            return back();
        }

        return Inertia::location($url);
    }

    /**
     * What everyone else sees while the account is paused.
     */
    public function paused(Request $request): Response|RedirectResponse
    {
        $tenant = CurrentTenant::get();

        if ($tenant === null || $tenant->billing()->hasAccess) {
            return redirect()->route('dashboard');
        }

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('billing/paused', [
            'business' => $tenant->name,
            'suspended' => $tenant->suspended_at !== null,
            'can_manage' => $user->canManageBusiness(),
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        return $tenant;
    }
}
