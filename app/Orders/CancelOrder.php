<?php

namespace App\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;

class CancelOrder
{
    public function handle(Order $order): Order
    {
        if (! $order->status->isOpen()) {
            OrderRules::fail('Only draft or confirmed orders can be cancelled.');
        }

        if ($order->amount_paid_cents > 0) {
            OrderRules::fail('This order has payments. Refund them before cancelling.');
        }

        // TODO: reverse the Stripe Tax transaction when one was committed.
        $order->status = OrderStatus::Cancelled;
        $order->cancelled_at = now();
        $order->save();

        $order->stops()->whereNotIn('status', ['done', 'cancelled'])->update(['status' => 'cancelled']);
        $order->invoice()->update(['status' => 'void']);

        return $order;
    }
}
