<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RentalPayment;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Payments\CompleteCheckout;
use App\Payments\Connect\AccountSync;
use App\Rentals\Payments\RentalPayments;
use App\Support\StripeGateway;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentMethod;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * Card payments on yards' connected accounts. Point Stripe's Connect
 * endpoint (events on connected accounts, STRIPE_CONNECT_WEBHOOK_SECRET)
 * at POST /webhooks/stripe with checkout.session.completed,
 * checkout.session.async_payment_succeeded and account.updated. A
 * platform endpoint signed with STRIPE_WEBHOOK_SECRET is accepted too.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CompleteCheckout $complete, RentalPayments $rentalPayments): Response
    {
        $event = $this->verified($request);

        if ($event === null) {
            WebhookDelivery::record('stripe', null, WebhookDelivery::REJECTED, detail: 'Invalid signature');

            return response('Invalid signature', 400);
        }

        $type = (string) $event->type;
        $account = is_string($event->account ?? null) ? $event->account : null;

        if ($type === 'account.updated') {
            return $this->accountUpdated($event, $account);
        }

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
            if (! $this->fromTenantAccount($rentalPayment->tenant_id, $account)) {
                WebhookDelivery::record('stripe', $type, WebhookDelivery::IGNORED, $rentalPayment->tenant_id, 'Different connected account');

                return response('Different account', 200);
            }

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

        if (! $this->fromTenantAccount($payment->tenant_id, $account)) {
            WebhookDelivery::record('stripe', $type, WebhookDelivery::IGNORED, $payment->tenant_id, 'Different connected account');

            return response('Different account', 200);
        }

        $this->process($type, $payment->tenant_id, function () use ($payment, $paymentIntent, $complete) {
            $complete->handle($payment->fresh() ?? $payment, $paymentIntent);
        });

        return response('OK', 200);
    }

    /**
     * The event, if it's signed by the Connect endpoint or the platform one.
     */
    private function verified(Request $request): ?Event
    {
        $secrets = array_filter([
            config('services.stripe.connect_webhook_secret'),
            config('services.stripe.webhook_secret'),
        ], fn ($secret) => is_string($secret) && $secret !== '');

        foreach ($secrets as $secret) {
            try {
                return Webhook::constructEvent(
                    $request->getContent(),
                    (string) $request->header('Stripe-Signature'),
                    $secret,
                );
            } catch (SignatureVerificationException|UnexpectedValueException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Whether a payment's event came from that yard's connected account.
     * Events without an account (the platform endpoint) are let through
     * as before; the session ID already ties them to one payment.
     */
    private function fromTenantAccount(int $tenantId, ?string $account): bool
    {
        if ($account === null) {
            return true;
        }

        return Tenant::query()->whereKey($tenantId)->value('stripe_account_id') === $account;
    }

    /**
     * Stripe changed a yard's account: charges or payouts switched on or
     * off, or new details are due.
     */
    private function accountUpdated(Event $event, ?string $account): Response
    {
        /** @var array<string, mixed> $object */
        $object = $event->data->object->toArray();
        $account ??= is_string($object['id'] ?? null) ? $object['id'] : null;

        $tenant = $account === null ? null : Tenant::query()->where('stripe_account_id', $account)->first();

        if ($tenant === null) {
            WebhookDelivery::record('stripe', 'account.updated', WebhookDelivery::IGNORED, detail: 'Unknown account');

            return response('Unknown account', 200);
        }

        $this->process('account.updated', $tenant->id, fn () => AccountSync::apply($tenant, $object));

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
