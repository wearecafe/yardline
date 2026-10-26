<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Tenancy\CurrentTenant;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        $invoice->load(['order' => fn ($q) => $q->with(['lines' => fn ($q) => $q->orderBy('position')->orderBy('id'), 'organization', 'customer', 'location', 'payments', 'deliveryZone:id,name'])]);
        $order = $invoice->order;

        return Inertia::render('print/invoice', [
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status,
                'terms' => $invoice->terms->label(),
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on->toDateString(),
                'balance_cents' => $invoice->balanceCents(),
            ],
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'po_number' => $order->po_number,
                'bill_to' => $order->organization
                    ? array_filter([$order->organization->name, $order->organization->address_line1, trim(implode(' ', array_filter([$order->organization->city, $order->organization->region, $order->organization->postal_code])))])
                    : array_filter([$order->customer->full_name ?? 'Walk-in']),
                'ship_to' => $order->delivery_address,
                'fulfillment' => $order->fulfillment->value,
                'yard' => $order->location->name,
                'lines' => $order->lines->map(fn ($l) => $l->only('id', 'sku', 'description', 'quantity', 'unit', 'unit_price_cents', 'line_total_cents', 'deposit_cents', 'pallets'))->values(),
                'delivery_fee_cents' => $order->delivery_fee_cents,
                'subtotal_cents' => $order->subtotal_cents,
                'tax_cents' => $order->tax_cents,
                'deposit_cents' => $order->deposit_cents,
                'total_cents' => $order->amountDueCents(),
                'paid_cents' => $order->amount_paid_cents,
            ],
            'business' => ['name' => CurrentTenant::get()->name ?? 'Yardline'],
        ]);
    }
}
