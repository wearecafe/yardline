<?php

namespace App\Orders;

use App\Accounting\QuickBooks\QuickBooks;
use App\Enums\OrderStatus;
use App\Jobs\ReverseTax;
use App\Models\Order;

class CancelOrder
{
    public function handle(Order $order): Order
    {
        if (! $order->status->isOpen()) {
            OrderRules::fail('Only draft or confirmed orders can be cancelled.');
        }

        if ($order->amount_paid_cents > 0) {
            OrderRules::fail('This order has payments. Refund them first, or use Refund and cancel.');
        }

        $order->status = OrderStatus::Cancelled;
        $order->cancelled_at = now();
        $order->save();

        $order->stops()->whereNotIn('status', ['done', 'cancelled'])->update(['status' => 'cancelled']);
        $order->invoice()->update(['status' => 'void']);
        QuickBooks::queue($order);

        // A sale already reported to Stripe Tax is taken back out of it.
        if ($order->tax_transaction_ref !== null) {
            ReverseTax::dispatch('order', $order->id)->afterCommit();
        }

        return $order;
    }
}
