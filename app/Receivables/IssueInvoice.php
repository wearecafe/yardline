<?php

namespace App\Receivables;

use App\Enums\PaymentStatus;
use App\Enums\PaymentTerms;
use App\Enums\Settlement;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Sequence;
use Carbon\CarbonImmutable;

/**
 * Every confirmed goods order gets an invoice: due by the account's terms
 * when it's on account, due on receipt when it's prepaid.
 */
class IssueInvoice
{
    public function forOrder(Order $order, ?CarbonImmutable $on = null): Invoice
    {
        if ($order->invoice !== null) {
            return $order->invoice;
        }

        $on ??= CarbonImmutable::today($order->tenant->timezone ?? 'America/Toronto');
        $terms = $order->settlement === Settlement::Account && $order->organization !== null
            ? $order->organization->payment_terms
            : PaymentTerms::DueOnReceipt;

        $invoice = $order->invoice()->create([
            'number' => 'INV-'.Sequence::next('invoices'),
            'organization_id' => $order->organization_id,
            'customer_id' => $order->customer_id,
            'terms' => $terms,
            'issued_on' => $on->toDateString(),
            'due_on' => $on->addDays($terms->days())->toDateString(),
            'status' => $order->payment_status === PaymentStatus::Paid ? 'paid' : 'open',
        ]);

        $order->setRelation('invoice', $invoice);

        return $invoice;
    }

    public static function syncStatus(Order $order): void
    {
        $order->invoice()->where('status', '!=', 'void')->update([
            'status' => $order->balanceCents() <= 0 ? 'paid' : 'open',
        ]);
    }
}
