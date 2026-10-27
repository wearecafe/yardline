<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RentalPayment;
use App\Models\WebhookDelivery;
use App\Payments\CompleteCheckout;
use App\Rentals\Payments\RentalPayments;
use App\Support\StripeGateway;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentMethod;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * Point Stripe (and, with Connect, the Connect endpoint) at POST /webhooks/stripe
 * with these events: checkout.session.completed,
 * checkout.session.async_payment_succeeded.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CompleteCheckout $complete, RentalPayments $rentalPayments): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            WebhookDelivery::record('stripe', null, WebhookDelivery::REJECTED, detail: 'Invalid signature');

            return response('Invalid signature', 400);
        }

        $type = (string) $event->type;

        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            WebhookDelivery::record('stripe', $type, WebhookDelivery::IGNORED);

            return response('Ignored', 200);
        }

        /** @var array<string, mixed> $session */
        $session = $event->data->object->toArray();
        $sessionId = is_string($session['id'] ?? null) ? $session['id'] : null;
        $paymentIntent = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;

        if ($type === 'checkout.session.completed' && ($session['payment_status'] ?? null) !== 'paid') {
            WebhookDelivery::record('stripe', $type, WebhookDelivery::IGNORED, detail: 'Awaiting payment');

            return response('Awaiting payment', 200); // async methods settle later
        }

        if ($sessionId === null) {
            WebhookDelivery::record('stripe', $type, WebhookDelivery::IGNORED, detail: 'Unknown session');

            return response('Unknown session', 200);
        }

        $rentalPayment = RentalPayment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('stripe_checkout_session_id', $sessionId)
            ->first();

        if ($rentalPayment !== null) {
            $this->process($type, $rentalPayment->tenant_id, function () use ($rentalPayment, $paymentIntent, $rentalPayments) {
                $rentalPayments->completeLink($rentalPayment->fresh() ?? $rentalPayment, $paymentIntent, $this->card($paymentIntent));
            });

            return response('OK', 200);
        }

        $payment = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('stripe_checkout_session_id', $sessionId)
            ->first();

        if ($payment === null) {
            WebhookDelivery::record('stripe', $type, WebhookDelivery::IGNORED, detail: 'Unknown session');

            return response('Unknown session', 200);
        }

        $this->process($type, $payment->tenant_id, function () use ($payment, $paymentIntent, $complete) {
            $complete->handle($payment->fresh() ?? $payment, $paymentIntent);
        });

        return response('OK', 200);
    }

    /**
     * Run the work as the tenant, logging the delivery. A failure is logged
     * and rethrown so Stripe retries it.
     *
     * @param  Closure(): void  $work
     */
    private function process(string $type, int $tenantId, Closure $work): void
    {
        try {
            CurrentTenant::run($tenantId, $work);
        } catch (Throwable $e) {
            WebhookDelivery::record('stripe', $type, WebhookDelivery::FAILED, $tenantId, $e->getMessage());

            throw $e;
        }

        WebhookDelivery::record('stripe', $type, WebhookDelivery::ACCEPTED, $tenantId);
    }

    /**
     * The saved card behind a payment, so the deposit can be held on it.
     *
     * @return array{payment_method?: string|null, brand?: string|null, last4?: string|null}
     */
    private function card(?string $paymentIntent): array
    {
        if ($paymentIntent === null) {
            return [];
        }

        try {
            $stripe = app(StripeGateway::class);
            $intent = $stripe->client()->paymentIntents->retrieve($paymentIntent, ['expand' => ['payment_method']], $stripe->requestOptions());
            $method = $intent->payment_method;

            if (! $method instanceof PaymentMethod || $method->card === null) {
                return [];
            }

            return [
                'payment_method' => $method->id,
                'brand' => (string) $method->card->brand,
                'last4' => (string) $method->card->last4,
            ];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
