<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\CompleteCheckout;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Point Stripe (and, with Connect, the Connect endpoint) at POST /webhooks/stripe
 * with these events: checkout.session.completed,
 * checkout.session.async_payment_succeeded.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CompleteCheckout $complete): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        if (! in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return response('Ignored', 200);
        }

        $session = $event->data->object;

        if ($event->type === 'checkout.session.completed' && $session->payment_status !== 'paid') {
            return response('Awaiting payment', 200); // async methods settle later
        }

        $payment = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('stripe_checkout_session_id', $session->id)
            ->first();

        if ($payment === null) {
            return response('Unknown session', 200);
        }

        CurrentTenant::run($payment->tenant_id, function () use ($payment, $session, $complete) {
            $complete->handle($payment->fresh(), is_string($session->payment_intent) ? $session->payment_intent : null);
        });

        return response('OK', 200);
    }
}
