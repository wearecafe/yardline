<?php

namespace App\Fulfillment;

use App\Enums\FulfillmentMethod;
use App\Models\DeliveryZone;
use App\Models\Order;

/**
 * The delivery fee for a goods order, by the yard's chosen method, plus
 * the load weight from product weights.
 */
class DeliveryPricer
{
    public function forOrder(Order $order): DeliveryQuote
    {
        $order->loadMissing('lines.product');
        $weight = $this->weightKg($order);

        if ($order->fulfillment !== FulfillmentMethod::Delivery) {
            return new DeliveryQuote(0, null, null, $weight);
        }

        $settings = DeliverySettings::for($order->tenant);
        $postal = $order->delivery_address['postal_code'] ?? null;

        return match ($settings->method) {
            'distance' => $this->byDistance($order, $settings, $weight),
            'truckload' => $this->byLoad($settings, $weight),
            default => $this->byZone($order, $settings, $weight, $postal),
        };
    }

    public function weightKg(Order $order): float
    {
        return round((float) $order->lines->sum(
            fn ($line) => $line->baseQuantity() * (float) ($line->product->weight_kg ?? 0),
        ), 1);
    }

    private function byZone(Order $order, DeliverySettings $settings, float $weight, ?string $postal): DeliveryQuote
    {
        $zone = DeliveryZone::forPostalCode($postal);

        if ($zone === null) {
            return new DeliveryQuote(
                $settings->outOfZoneCents,
                null,
                null,
                $weight,
                $settings->outOfZoneCents > 0 ? null : 'That address isn’t in a delivery zone. Set the fee by hand.',
            );
        }

        return new DeliveryQuote($zone->fee_cents, $zone->id, null, $weight);
    }

    private function byDistance(Order $order, DeliverySettings $settings, float $weight): DeliveryQuote
    {
        if ($order->delivery_km === null) {
            return new DeliveryQuote($settings->baseCents, null, null, $weight, 'Enter the distance from the yard to price this delivery.');
        }

        return new DeliveryQuote(
            $settings->baseCents + (int) round((float) $order->delivery_km * $settings->perKmCents),
            null,
            null,
            $weight,
        );
    }

    private function byLoad(DeliverySettings $settings, float $weight): DeliveryQuote
    {
        $loads = max(1, (int) ceil($weight / $settings->loadCapacityKg));

        return new DeliveryQuote($loads * $settings->perLoadCents, null, $loads, $weight);
    }
}
