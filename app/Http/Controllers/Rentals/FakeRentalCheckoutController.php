<?php

namespace App\Http\Controllers\Rentals;

use App\Http\Controllers\Controller;
use App\Models\RentalPayment;
use App\Rentals\Payments\RentalPayments;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\RedirectResponse;

/**
 * Stand-in for Stripe Checkout on rentals (local development and the demo
 * yard): opening the link pays it and saves a test card.
 */
class FakeRentalCheckoutController extends Controller
{
    public function __invoke(string $session, RentalPayments $payments): RedirectResponse
    {
        $payment = RentalPayment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('stripe_checkout_session_id', $session)
            ->firstOrFail();

        CurrentTenant::set($payment->tenant_id);

        $allowed = Demo::isCurrentTenant()
            || (! app()->isProduction() && config('yardline.payments.driver') === 'fake');
        abort_unless($allowed, 404);

        $payments->completeLink($payment->fresh() ?? $payment, 'pi_fake_'.$payment->id, [
            'payment_method' => 'pm_fake_visa',
            'brand' => 'visa',
            'last4' => '4242',
        ]);

        $token = $payment->rental->signing_token;

        return $token ? to_route('rentals.public-sign', $token) : to_route('rentals.show', $payment->rental_id);
    }
}
