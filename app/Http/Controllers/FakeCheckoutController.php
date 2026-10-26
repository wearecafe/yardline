<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\CompleteCheckout;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Local development stand-in for Stripe Checkout: opening the link pays it.
 */
class FakeCheckoutController extends Controller
{
    public function __invoke(string $session, CompleteCheckout $complete): RedirectResponse
    {
        abort_if(app()->isProduction() || config('yardline.payments.driver') !== 'fake', 404);

        $payment = Payment::query()->where('stripe_checkout_session_id', $session)->firstOrFail();

        $complete->handle($payment, 'pi_fake_'.$payment->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Test card payment received.']);

        return to_route('orders.show', $payment->order_id);
    }
}
