<?php

namespace App\Orders;

use App\Enums\OrderStatus;
use App\Enums\Settlement;
use App\Fulfillment\CompleteShipment;
use App\Fulfillment\LoadShipment;
use App\Fulfillment\StartPicking;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Releases everything left on an order in one step, for the counter: a
 * pick list for what's left, loaded at once. Pickups are handed over;
 * deliveries go to the dispatch board.
 */
class FulfillOrder
{
    public function __construct(
        private readonly StartPicking $startPicking,
        private readonly LoadShipment $load,
        private readonly CompleteShipment $complete,
    ) {}

    public function handle(Order $order, User $user): Order
    {
        if ($order->status !== OrderStatus::Confirmed) {
            OrderRules::fail('Only confirmed orders can be released.');
        }

        if ($order->settlement === Settlement::Prepaid && $order->balanceCents() > 0) {
            OrderRules::fail('Take payment before releasing this order.');
        }

        return DB::transaction(function () use ($order, $user) {
            $shipment = $order->shipments()->inYard()->first()
                ?? $this->startPicking->handle($order, $user, $user);

            $this->load->handle($shipment, $user);

            // A business that checks loads holds it at Loaded for a sign-off.
            if (! $shipment->isDelivery() && $shipment->refresh()->isReleased()) {
                $this->complete->handle($shipment->refresh(), $order->customer->full_name ?? 'Counter');
            }

            return $order->refresh();
        });
    }
}
