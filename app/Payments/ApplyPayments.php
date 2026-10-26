<?php

namespace App\Payments;

use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Enums\Settlement;
use App\Models\Order;
use App\Orders\CommitOrderTax;
use App\Receivables\IssueInvoice;

/**
 * Re-totals an order's successful payments and updates its payment status.
 */
class ApplyPayments
{
    public function __construct(private readonly CommitOrderTax $commitTax) {}

    public function handle(Order $order): Order
    {
        $paid = (int) $order->payments()->where('status', PaymentState::Succeeded->value)->sum('amount_cents');

        $order->amount_paid_cents = $paid;
        $order->payment_status = match (true) {
            $paid <= 0 => PaymentStatus::Unpaid,
            $paid >= $order->amountDueCents() => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };
        $order->save();

        IssueInvoice::syncStatus($order);

        if ($order->settlement === Settlement::Prepaid && $order->payment_status === PaymentStatus::Paid) {
            $this->commitTax->handle($order);
        }

        return $order;
    }
}
