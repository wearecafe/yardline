<?php

namespace App\Http\Controllers\Webhooks;

use App\Billing\Billing;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * Tenants' YardRunner subscriptions. A separate endpoint from the yards'
 * card payments (StripeWebhookController), with its own signing secret:
 * point a webhook on YardRunner's Stripe account at POST
 * /webhooks/stripe/billing with customer.subscription.created,
 * customer.subscription.updated, customer.subscription.deleted,
 * customer.subscription.paused and customer.subscription.resumed.
 *
 * The subscription in each event is copied onto the tenant, so nothing
 * here calls Stripe back.
 */
class StripeBillingWebhookController extends Controller
{
    public const EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
    ];

    private const PROVIDER = 'stripe_billing';

    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('yardline.billing.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            WebhookDelivery::record(self::PROVIDER, null, WebhookDelivery::REJECTED, detail: 'Invalid signature');

            return response('Invalid signature', 400);
        }

        $type = (string) $event->type;

        if (! in_array($type, self::EVENTS, true)) {
            WebhookDelivery::record(self::PROVIDER, $type, WebhookDelivery::IGNORED);

            return response('Ignored', 200);
        }

        /** @var array<string, mixed> $subscription */
        $subscription = $event->data->object->toArray();
        $tenant = $this->tenantFor($subscription);

        if ($tenant === null) {
            WebhookDelivery::record(self::PROVIDER, $type, WebhookDelivery::IGNORED, detail: 'No tenant for this subscription');

            return response('Unknown tenant', 200);
        }

        try {
            $applied = Billing::apply($tenant, $subscription, (int) $event->created);
        } catch (Throwable $e) {
            WebhookDelivery::record(self::PROVIDER, $type, WebhookDelivery::FAILED, $tenant->id, $e->getMessage());

            throw $e; // Stripe retries
        }

        WebhookDelivery::record(self::PROVIDER, $type, $applied ? WebhookDelivery::ACCEPTED : WebhookDelivery::IGNORED, $tenant->id, $applied ? null : 'Older than what we have');

        return response('OK', 200);
    }

    /**
     * By the tenant ID put on the subscription at checkout, then by the
     * subscription or customer we already know.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function tenantFor(array $subscription): ?Tenant
    {
        $id = $subscription['metadata']['tenant_id'] ?? null;

        if (is_numeric($id) && ($tenant = Tenant::query()->find((int) $id)) !== null) {
            return $tenant;
        }

        return Tenant::query()
            ->where('stripe_subscription_id', $subscription['id'] ?? '')
            ->orWhere(fn ($q) => $q->whereNotNull('stripe_customer_id')->where('stripe_customer_id', $subscription['customer'] ?? ''))
            ->first();
    }
}
