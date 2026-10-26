<?php

namespace App\Receivables;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Inventory\InventoryLedger;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Sequence;
use App\Models\User;
use App\Orders\OrderRules;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Material coming back. Good stock goes back on the shelf less a
 * restocking fee; damaged stock is credited but not restocked. The credit,
 * with its share of the original tax, sits on the account until it's
 * applied or refunded.
 */
class ProcessReturn
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * @param  list<array{order_line_id: int, quantity: string|float, restock?: bool}>  $lines
     */
    public function handle(Order $order, array $lines, User $user, ?string $reason = null, ?float $restockingPercent = null, ?Location $location = null): CreditNote
    {
        if (! in_array($order->status, [OrderStatus::Fulfilled, OrderStatus::Confirmed, OrderStatus::Returned], true)) {
            OrderRules::fail('Only orders that have shipped can take a return.');
        }

        $restockingPercent ??= ReturnsSettings::for($order->tenant)->restockingPercent;
        $location ??= $order->location;
        $orderLines = $order->lines()->with(['product', 'productUnit'])->get()->keyBy('id');

        return DB::transaction(function () use ($order, $lines, $user, $reason, $restockingPercent, $location, $orderLines) {
            $credit = CreditNote::query()->create([
                'number' => 'CN-'.Sequence::next('credit_notes'),
                'kind' => 'return',
                'organization_id' => $order->organization_id,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'location_id' => $location->id,
                'reason' => $reason,
                'issued_on' => now()->toDateString(),
                'created_by' => $user->id,
            ]);

            $subtotal = 0;
            $restockable = 0;
            $tax = 0;
            $any = false;

            foreach ($lines as $row) {
                /** @var OrderLine|null $line */
                $line = $orderLines->get((int) $row['order_line_id']);
                $quantity = round((float) $row['quantity'], 3);

                if ($line === null || $quantity <= 0) {
                    continue;
                }

                $returnable = (float) $line->shipped_quantity - (float) $line->returned_quantity;

                if ($quantity > $returnable + 1e-9) {
                    OrderRules::fail("Only {$this->qty($returnable)} {$line->unit} of {$line->description} can come back.", 'lines');
                }

                $restock = (bool) ($row['restock'] ?? true);
                $amount = Money::extend($quantity, $line->unit_price_cents);
                $lineTax = $line->line_total_cents > 0 ? (int) round($line->tax_cents * $amount / $line->line_total_cents) : 0;
                $base = $quantity * (float) ($line->unit_factor ?: 1);

                $credit->lines()->create([
                    'order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $quantity,
                    'unit' => $line->unit,
                    'base_quantity' => $base,
                    'unit_price_cents' => $line->unit_price_cents,
                    'line_total_cents' => $amount,
                    'tax_cents' => $lineTax,
                    'restock' => $restock,
                ]);

                if ($restock && $line->product?->track_inventory) {
                    $this->ledger->record($line->product, $location, $base, StockMovementType::CustomerReturn, $credit, $user, "Return {$credit->number}");
                }

                $line->returned_quantity = (string) ((float) $line->returned_quantity + $quantity);
                $line->save();

                $subtotal += $amount;
                $tax += $lineTax;
                $restockable += $restock ? $amount : 0;
                $any = true;
            }

            if (! $any) {
                OrderRules::fail('Enter what is coming back.', 'lines');
            }

            // The fee comes off the goods that go back on the shelf, with its share of tax.
            $fee = (int) round($restockable * $restockingPercent / 100);
            $feeTax = $subtotal > 0 ? (int) round($tax * $fee / $subtotal) : 0;

            $credit->forceFill([
                'subtotal_cents' => $subtotal,
                'restocking_fee_cents' => $fee,
                'tax_cents' => $tax - $feeTax,
                'total_cents' => $subtotal - $fee + $tax - $feeTax,
            ])->save();

            return $credit;
        });
    }

    private function qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    }
}
