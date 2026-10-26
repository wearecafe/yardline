<?php

namespace App\Tax;

use App\Enums\FulfillmentMethod;
use App\Models\Order;
use App\Support\StripeGateway;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe Tax: a calculation at confirmation, a transaction when committed.
 *
 * Pickup orders are taxed at the yard's address (point of sale); deliveries
 * at the delivery address.
 */
class StripeTaxCalculator implements TaxCalculator
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function calculate(Order $order): TaxResult
    {
        $address = $order->fulfillment === FulfillmentMethod::Delivery && filled($order->delivery_address)
            ? $this->deliveryAddress($order)
            : $order->location->addressForTax();

        if (blank($address['postal_code']) || blank($address['country'])) {
            throw new TaxCalculationFailed(
                $order->fulfillment === FulfillmentMethod::Delivery
                    ? 'Add a postal code to the delivery address so tax can be calculated.'
                    : "Add an address for {$order->location->name} so tax can be calculated.",
            );
        }

        $lineItems = $order->lines
            ->filter(fn ($line) => $line->line_total_cents > 0)
            ->map(function ($line): array {
                $item = [
                    'amount' => $line->line_total_cents,
                    'reference' => 'line_'.$line->id,
                    'tax_behavior' => 'exclusive',
                ];

                if (filled($line->product?->tax_code)) {
                    $item['tax_code'] = (string) $line->product->tax_code;
                }

                return $item;
            })
            ->values()
            ->all();

        if ($lineItems === []) {
            return new TaxResult([], 0);
        }

        $customerDetails = [
            'address' => [
                'line1' => $address['line1'] ?: null,
                'line2' => $address['line2'] ?: null,
                'city' => $address['city'] ?: null,
                'state' => $address['state'] ?: null,
                'postal_code' => $address['postal_code'],
                'country' => (string) $address['country'],
            ],
            'address_source' => 'shipping',
        ];

        if ($order->organization?->tax_exempt) {
            $customerDetails['taxability_override'] = 'customer_exempt';
        }

        try {
            $calculation = $this->stripe->client()->tax->calculations->create([
                'currency' => strtolower($order->tenant->currency),
                'line_items' => $lineItems,
                'customer_details' => $customerDetails,
                'expand' => ['line_items'],
            ], $this->stripe->requestOptions());
        } catch (ApiErrorException $e) {
            throw new TaxCalculationFailed('Stripe Tax could not calculate this order: '.$e->getMessage(), previous: $e);
        }

        $lines = [];
        foreach ($calculation->line_items->data ?? [] as $item) {
            $lineId = (int) str_replace('line_', '', (string) $item->reference);
            $lines[$lineId] = (int) $item->amount_tax;
        }

        return new TaxResult($lines, (int) $calculation->tax_amount_exclusive, $calculation->id);
    }

    public function commit(Order $order): ?string
    {
        if ($order->tax_calculation_ref === null || $order->tax_transaction_ref !== null) {
            return $order->tax_transaction_ref;
        }

        try {
            $transaction = $this->stripe->client()->tax->transactions->createFromCalculation([
                'calculation' => $order->tax_calculation_ref,
                'reference' => $order->number,
            ], $this->stripe->requestOptions());
        } catch (ApiErrorException $e) {
            throw new TaxCalculationFailed('Stripe Tax could not record this order: '.$e->getMessage(), previous: $e);
        }

        return $transaction->id;
    }

    /**
     * @return array{line1: string|null, line2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string}
     */
    private function deliveryAddress(Order $order): array
    {
        $address = $order->delivery_address ?? [];

        return [
            'line1' => $address['line1'] ?? null,
            'line2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['region'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'country' => $address['country'] ?? 'CA',
        ];
    }
}
