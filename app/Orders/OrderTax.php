<?php

namespace App\Orders;

use App\Enums\FulfillmentMethod;
use App\Models\Order;
use App\Tax\TaxAddress;
use App\Tax\TaxCalculationFailed;
use App\Tax\TaxLine;
use App\Tax\TaxRequest;

/**
 * Builds the tax request for a goods order. Pickups are taxed at the yard,
 * deliveries at the delivery address.
 */
final class OrderTax
{
    public static function request(Order $order): TaxRequest
    {
        $delivery = $order->fulfillment === FulfillmentMethod::Delivery && filled($order->delivery_address);
        $address = $delivery
            ? TaxAddress::fromArray($order->delivery_address)
            : TaxAddress::fromArray($order->location->addressForTax());

        if (! $address->isComplete()) {
            throw new TaxCalculationFailed($delivery
                ? 'Add a postal code to the delivery address so tax can be calculated.'
                : "Add an address for {$order->location->name} so tax can be calculated.");
        }

        $lines = [];
        foreach ($order->lines as $line) {
            $code = $line->product !== null && ! $line->product->taxable ? TaxLine::NONTAXABLE : $line->product?->tax_code;
            $lines[] = new TaxLine('line_'.$line->id, $line->line_total_cents, $code);
        }

        if ($order->delivery_fee_cents > 0) {
            $lines[] = new TaxLine('delivery', $order->delivery_fee_cents, TaxLine::SHIPPING);
        }

        return new TaxRequest(
            $order->tenant->currency,
            $address,
            $lines,
            (bool) $order->organization?->tax_exempt,
        );
    }
}
