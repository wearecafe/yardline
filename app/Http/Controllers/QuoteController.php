<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Orders\OrderRules;
use App\Orders\SendQuote;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sending quotes, and the page the customer sees.
 */
class QuoteController extends Controller
{
    public function send(Request $request, Order $order, SendQuote $send): RedirectResponse
    {
        $data = $request->validate([
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
            'email' => ['boolean'],
        ]);

        $send->handle(
            $order,
            $request->user(),
            isset($data['expires_on']) ? CarbonImmutable::parse($data['expires_on']) : null,
            (bool) ($data['email'] ?? true),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Quote {$order->number} sent (revision {$order->revision})."]);

        return back();
    }

    public function show(string $token): Response
    {
        $order = $this->find($token);
        $order->load(['lines' => fn ($q) => $q->orderBy('position')->orderBy('id'), 'organization:id,name', 'customer:id,first_name,last_name', 'location', 'tenant']);

        return Inertia::render('public/quote', [
            'quote' => [
                'number' => $order->number,
                'revision' => $order->revision,
                'status' => $order->isQuote() ? ($order->quoteExpired() ? 'expired' : ($order->quote_accepted_at ? 'accepted' : 'open')) : ($order->status->value === 'cancelled' ? 'cancelled' : 'ordered'),
                'quoted_at' => $order->quoted_at?->toIso8601String(),
                'expires_on' => $order->quote_expires_on?->toDateString(),
                'accepted_at' => $order->quote_accepted_at?->toIso8601String(),
                'accepted_by' => $order->quote_accepted_by,
                'organization' => $order->organization?->name,
                'customer' => $order->customer?->full_name,
                'po_number' => $order->po_number,
                'fulfillment' => $order->fulfillment->value,
                'delivery_address' => $order->delivery_address,
                'yard' => ['name' => $order->location->name, 'address' => trim(implode(', ', array_filter([$order->location->address_line1, $order->location->city])))],
                'lines' => $order->lines->map(fn ($line) => $line->only('id', 'sku', 'description', 'quantity', 'unit', 'unit_price_cents', 'line_total_cents', 'deposit_cents'))->all(),
                'subtotal_cents' => $order->subtotal_cents,
                'tax_cents' => $order->tax_cents,
                'deposit_cents' => $order->deposit_cents,
                'total_cents' => $order->total_cents,
            ],
            'business' => ['name' => $order->tenant->name],
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $order = $this->find($token);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'po_number' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $order->isQuote()) {
            OrderRules::fail('This quote has already been turned into an order.');
        }

        if ($order->quoteExpired()) {
            OrderRules::fail('This quote has expired. Call the yard for an updated price.');
        }

        $order->forceFill([
            'quote_accepted_at' => now(),
            'quote_accepted_by' => $data['name'],
            'po_number' => $data['po_number'] ?: $order->po_number,
        ])->save();

        return back();
    }

    private function find(string $token): Order
    {
        $order = Order::query()->withoutGlobalScopes()->where('quote_token', $token)->firstOrFail();
        CurrentTenant::set($order->tenant);

        return $order;
    }
}
