<?php

namespace App\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Inventory\InventoryLedger;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rented equipment comes back to the yard.
 *
 * TODO: deposit settlement (refund, or apply against damage / extra days).
 */
class ReturnRental
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function handle(Order $order, User $user): Order
    {
        if (! $order->isRental() || $order->status !== OrderStatus::Fulfilled) {
            OrderRules::fail('Only rentals that are out can be returned.');
        }

        return DB::transaction(function () use ($order, $user) {
            foreach ($order->lines()->with('product')->get() as $line) {
                if ($line->product?->track_inventory) {
                    $this->ledger->record($line->product, $order->location, (float) $line->quantity, StockMovementType::RentalReturn, $order, $user);
                }
            }

            $order->status = OrderStatus::Returned;
            $order->returned_at = now();
            $order->save();

            return $order;
        });
    }
}
