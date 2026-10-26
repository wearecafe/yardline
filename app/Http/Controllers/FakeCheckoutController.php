<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\CompleteCheckout;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Stand-in for Stripe Checkout in local development and the demo yard:
 * opening the link pays it.
 */
class FakeCheckoutController extends Controller
{
    public function __invoke(string $session, CompleteCheckout $complete): RedirectResponse
    {
        // Payment::query() is tenant-scoped, so a link only resolves for the
        // signed-in user's own yard.
        $payment = Payment::query()->where('stripe_checkout_session_id', $session)->firstOrFail();

        // Allowed for the demo yard anywhere; for real yards, only off production
        // with the fake driver configured.
        $allowed = Demo::isCurrentTenant()
            || (! app()->isProduction() && config('yardline.payments.driver') === 'fake');
        abort_unless($allowed, 404);

        $complete->handle($payment, 'pi_fake_'.$payment->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Test card payment received.']);

        return to_route('orders.show', $payment->order_id);
    }
}
