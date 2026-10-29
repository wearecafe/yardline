<?php

namespace App\Rentals\Payments;

use App\Models\Customer;
use App\Models\RentalPayment;
use App\Payments\CheckoutLink;
use App\Payments\Connect\PaymentsNotSetUp;
use App\Support\StripeGateway;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

/**
 * Stripe: saved cards are PaymentMethods on a Stripe Customer; deposit holds
 * are manual-capture PaymentIntents (card holds last about seven days).
 */
class StripeRentalGateway implements RentalGateway
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function charge(Customer $customer, int $cents, string $description, array $metadata): string
    {
        return $this->intent($customer, $cents, $description, $metadata, 'automatic');
    }

    public function hold(Customer $customer, int $cents, string $description, array $metadata): string
    {
        return $this->intent($customer, $cents, $description, $metadata, 'manual');
    }

    public function capture(string $reference, int $cents): void
    {
        // The fee is set on capture: a deposit hold only pays on what's kept.
        $this->stripe->client()->paymentIntents->capture($reference, [
            'amount_to_capture' => $cents,
            'application_fee_amount' => StripeGateway::applicationFee($cents),
        ], $this->stripe->requestOptions());
    }

    public function release(string $reference): void
    {
        $this->stripe->client()->paymentIntents->cancel($reference, [], $this->stripe->requestOptions());
    }

    public function refund(string $reference, int $cents): void
    {
        // YardRunner's fee goes back to the yard with the refund; Stripe keeps its own.
        $this->stripe->client()->refunds->create([
            'payment_intent' => $reference,
            'amount' => $cents,
            'refund_application_fee' => true,
        ], $this->stripe->requestOptions());
    }

    public function paymentLink(RentalPayment $payment, string $description, string $returnUrl): CheckoutLink
    {
        $rental = $payment->rental;
        $customer = $rental->customer;
        $metadata = [
            'yardrunner_tenant_id' => (string) $payment->tenant_id,
            'yardrunner_rental_id' => (string) $rental->id,
            'yardrunner_rental_payment_id' => (string) $payment->id,
        ];

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $rental->number,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($rental->tenant->currency),
                    'unit_amount' => $payment->amount_cents,
                    'product_data' => ['name' => $description],
                ],
            ]],
            'metadata' => $metadata,
            // Saves the card so the deposit can be held on it at pickup.
            'payment_intent_data' => [
                'metadata' => $metadata,
                'setup_future_usage' => 'off_session',
                'application_fee_amount' => StripeGateway::applicationFee($payment->amount_cents),
            ],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ];

        if ($customer !== null) {
            $params['customer'] = $this->stripeCustomer($customer);
        }

        $session = $this->stripe->client()->checkout->sessions->create($params, $this->stripe->chargeOptions());

        return new CheckoutLink($session->id, (string) $session->url);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function intent(Customer $customer, int $cents, string $description, array $metadata, string $capture): string
    {
        if ($customer->stripe_payment_method_id === null) {
            throw new CardDeclined("{$customer->full_name} has no card on file.");
        }

        try {
            $options = $this->stripe->chargeOptions();
        } catch (PaymentsNotSetUp $e) {
            throw new CardDeclined($e->getMessage(), previous: $e);
        }

        $params = [
            'amount' => $cents,
            'currency' => strtolower($customer->tenant->currency),
            'payment_method' => $customer->stripe_payment_method_id,
            'off_session' => true,
            'confirm' => true,
            'capture_method' => $capture,
            'description' => $description,
            'metadata' => $metadata,
        ];

        // Holds pay their fee when captured (see capture()).
        if ($capture === 'automatic') {
            $params['application_fee_amount'] = StripeGateway::applicationFee($cents);
        }

        try {
            $intent = $this->stripe->client()->paymentIntents->create([
                ...$params,
                'customer' => $this->stripeCustomer($customer),
            ], $options);
        } catch (CardException $e) {
            throw new CardDeclined('The card was declined: '.$e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new CardDeclined('Stripe couldn’t take the payment: '.$e->getMessage(), previous: $e);
        }

        return $intent->id;
    }

    private function stripeCustomer(Customer $customer): string
    {
        if ($customer->stripe_customer_id !== null) {
            return $customer->stripe_customer_id;
        }

        $created = $this->stripe->client()->customers->create(array_filter([
            'name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'metadata' => ['yardrunner_customer_id' => (string) $customer->id],
        ]), $this->stripe->requestOptions());

        $customer->forceFill(['stripe_customer_id' => $created->id])->saveQuietly();

        return $created->id;
    }
}
