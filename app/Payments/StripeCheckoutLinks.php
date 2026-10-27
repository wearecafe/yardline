<?php

namespace App\Payments;

use App\Models\Payment;
use App\Support\StripeGateway;

class StripeCheckoutLinks implements CheckoutLinks
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function create(Payment $payment): CheckoutLink
    {
        $order = $payment->order;
        $metadata = [
            'yardline_tenant_id' => (string) $payment->tenant_id,
            'yardline_order_id' => (string) $order->id,
            'yardline_payment_id' => (string) $payment->id,
        ];

        $session = $this->stripe->client()->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => $order->number,
            'customer_email' => $order->customer->email ?? $order->organization->email ?? null,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($order->tenant->currency),
                    'unit_amount' => $payment->amount_cents,
                    'product_data' => [
                        'name' => $order->isRental()
                            ? "Rental {$order->number} (including deposit)"
                            : "Order {$order->number}",
                    ],
                ],
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata,
                'application_fee_amount' => StripeGateway::applicationFee($payment->amount_cents),
            ],
            'success_url' => route('orders.show', $order).'?paid=1',
            'cancel_url' => route('orders.show', $order),
        ], $this->stripe->chargeOptions());

        return new CheckoutLink($session->id, $session->url);
    }
}
