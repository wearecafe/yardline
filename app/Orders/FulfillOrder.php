<?php

namespace App\Orders;

use App\Enums\OrderStatus;
use App\Enums\Settlement;
use App\Enums\StockMovementType;
use App\Inventory\InventoryLedger;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Material leaves the yard (picked up, loaded for delivery, or rented out).
 */
class FulfillOrder
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function handle(Order $order, User $user): Order
    {
        if ($order->status !== OrderStatus::Confirmed) {
            OrderRules::fail('Only confirmed orders can be released.');
        }

        if ($order->settlement === Settlement::Prepaid && $order->balanceCents() > 0) {
            OrderRules::fail('Take payment before releasing this order.');
        }

        return DB::transaction(function () use ($order, $user) {
            $type = $order->isRental() ? StockMovementType::RentalOut : StockMovementType::Sale;

            foreach ($order->lines()->with('product')->get() as $line) {
                if ($line->product?->track_inventory) {
                    $this->ledger->record($line->product, $order->location, -1 * (float) $line->quantity, $type, $order, $user);
                }
            }

            $order->status = OrderStatus::Fulfilled;
            $order->fulfilled_at = now();
            $order->save();

            return $order;
        });
    }
}
