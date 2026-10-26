<?php

namespace App\Orders;

use App\Models\Order;
use App\Models\User;
use App\Notifications\QuoteMessage;
use App\Support\Demo;
use App\Support\Money;
use App\Tax\TaxCalculationFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a draft to the customer as a quote. Prices hold until it expires.
 * Each send after a change is a new revision, with a snapshot of what the
 * customer was shown.
 */
class SendQuote
{
    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly RecalculateOrder $recalculate) {}

    public function handle(Order $order, User $user, ?CarbonImmutable $expiresOn = null, bool $email = true): Order
    {
        if (! $order->isDraft()) {
            OrderRules::fail('Only drafts can be sent as a quote.');
        }

        if ($order->lines()->doesntExist()) {
            OrderRules::fail('Add at least one line before sending a quote.');
        }

        // Re-price unless the current quote is still good, then add tax.
        try {
            $this->recalculate->handle($order, withTax: true);
        } catch (TaxCalculationFailed $e) {
            OrderRules::fail($e->getMessage());
        }

        DB::transaction(function () use ($order, $user, $expiresOn) {
            $order->forceFill([
                'quoted_at' => now(),
                'quote_expires_on' => ($expiresOn ?? CarbonImmutable::today()->addDays(self::DEFAULT_DAYS))->toDateString(),
                'revision' => $order->revision + 1,
                'quote_token' => $order->quote_token ?? Str::random(40),
                'quote_accepted_at' => null,
                'quote_accepted_by' => null,
            ])->save();

            $order->revisions()->create([
                'revision' => $order->revision,
                'snapshot' => $this->snapshot($order),
                'created_by' => $user->id,
            ]);
        });

        if ($email && $order->customer?->email && ! Demo::isCurrentTenant()) {
            try {
                $order->customer->notify(new QuoteMessage($order));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        $order->loadMissing('lines');

        return [
            'lines' => $order->lines->map(fn ($line) => [
                'sku' => $line->sku,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price_cents' => $line->unit_price_cents,
                'line_total_cents' => $line->line_total_cents,
            ])->values()->all(),
            'subtotal_cents' => $order->subtotal_cents,
            'tax_cents' => $order->tax_cents,
            'deposit_cents' => $order->deposit_cents,
            'total_cents' => $order->total_cents,
            'total' => Money::format($order->total_cents),
            'expires_on' => $order->quote_expires_on?->toDateString(),
            'fulfillment' => $order->fulfillment->value,
            'delivery_address' => $order->delivery_address,
        ];
    }
}
