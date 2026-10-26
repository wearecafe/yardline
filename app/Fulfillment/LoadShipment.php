<?php

namespace App\Fulfillment;

use App\Enums\OrderStatus;
use App\Enums\Settlement;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementType;
use App\Enums\StopStatus;
use App\Inventory\InventoryLedger;
use App\Models\Shipment;
use App\Models\User;
use App\Orders\OrderRules;
use Illuminate\Support\Facades\DB;

/**
 * The load is on the truck (delivery) or set out at the yard (pickup).
 * Stock leaves now, by what was actually picked; anything short stays
 * open on the order for another shipment.
 */
class LoadShipment
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function handle(Shipment $shipment, User $user): Shipment
    {
        $order = $shipment->order;

        if ($shipment->status !== ShipmentStatus::Picking) {
            OrderRules::fail('This load has already gone.');
        }

        if ($order->settlement === Settlement::Prepaid && $order->balanceCents() > 0) {
            OrderRules::fail('Take payment before releasing this order.');
        }

        return DB::transaction(function () use ($shipment, $order, $user) {
            $shipment->load('lines.orderLine.product', 'lines.orderLine.productUnit');
            $weight = 0.0;

            foreach ($shipment->lines as $line) {
                $orderLine = $line->orderLine;
                $quantity = $line->loadedQuantity();
                $base = $quantity * (float) ($orderLine->unit_factor ?: 1);
                $product = $orderLine->product;

                if ($product?->track_inventory && $base > 0) {
                    $this->ledger->record($product, $order->location, -1 * $base, StockMovementType::Sale, $shipment, $user);
                }

                $weight += $base * (float) ($product->weight_kg ?? 0);

                // Never ship more than was ordered on the books.
                $orderLine->shipped_quantity = (string) min((float) $orderLine->quantity, (float) $orderLine->shipped_quantity + $quantity);
                $orderLine->save();
            }

            $shipment->forceFill([
                'status' => $shipment->isDelivery() ? ShipmentStatus::Loaded : ShipmentStatus::Ready,
                'picked_at' => $shipment->picked_at ?? now(),
                'loaded_at' => now(),
                'weight_kg' => round($weight, 1),
                'picker_id' => $shipment->picker_id ?? $user->id,
            ])->save();

            // The run planned for the order now carries this load.
            $planned = $shipment->isDelivery()
                ? $order->stops()->whereNotIn('status', [StopStatus::Done->value, StopStatus::Cancelled->value])->first()
                : null;

            if ($planned !== null) {
                $planned->forceFill(['stoppable_type' => $shipment->getMorphClass(), 'stoppable_id' => $shipment->id])->save();
            } elseif ($shipment->isDelivery() && $shipment->stop()->doesntExist()) {
                $shipment->stop()->create([
                    'kind' => 'delivery',
                    'status' => StopStatus::Unscheduled,
                    'date' => $order->requested_on?->toDateString(),
                    'address' => $order->delivery_address,
                    'needs' => $order->needs_boom ? 'Needs boom truck' : null,
                    'duration_minutes' => 60,
                ]);
            }

            $open = $order->lines()->get()->contains(fn ($l) => $l->remainingQuantity() > 0);

            $order->forceFill([
                'fulfillment_status' => $open ? 'partly_shipped' : 'shipped',
                'status' => $open ? $order->status : OrderStatus::Fulfilled,
                'fulfilled_at' => $open ? $order->fulfilled_at : now(),
            ])->save();

            return $shipment;
        });
    }
}
