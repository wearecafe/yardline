<?php

namespace App\Purchasing;

use App\Enums\StockMovementType;
use App\Inventory\InventoryLedger;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sequence;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\User;
use App\Orders\OrderRules;
use Illuminate\Support\Facades\DB;

/**
 * Moves stock between yards: out of one, into the other, in the same run.
 */
class TransferStock
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * @param  list<array{product_id: int, quantity: string|float, lot_id?: int|null}>  $lines
     */
    public function handle(Location $from, Location $to, array $lines, User $user, ?string $notes = null): StockTransfer
    {
        if ($from->id === $to->id) {
            OrderRules::fail('Pick two different yards.', 'to_location_id');
        }

        return DB::transaction(function () use ($from, $to, $lines, $user, $notes) {
            $transfer = StockTransfer::query()->create([
                'number' => 'TR-'.Sequence::next('transfers'),
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => 'done',
                'notes' => $notes,
                'created_by' => $user->id,
            ]);

            foreach ($lines as $row) {
                $product = Product::query()->findOrFail((int) $row['product_id']);
                $quantity = round((float) $row['quantity'], 3);

                if ($quantity <= 0) {
                    continue;
                }

                $available = (float) $this->ledger->onHand($product, $from);

                if ($quantity > $available + 1e-9) {
                    OrderRules::fail("Only {$available} of {$product->name} at {$from->name}.", 'lines');
                }

                $fromLot = ! empty($row['lot_id']) ? StockLot::query()->where('location_id', $from->id)->find((int) $row['lot_id']) : null;
                $toLot = $fromLot !== null
                    ? StockLot::query()->firstOrCreate(['product_id' => $product->id, 'location_id' => $to->id, 'code' => $fromLot->code], ['received_on' => $fromLot->received_on])
                    : null;

                $transfer->lines()->create(['product_id' => $product->id, 'stock_lot_id' => $fromLot?->id, 'quantity' => $quantity]);
                // Stock arrives at the cost it left the other yard at.
                $out = $this->ledger->record($product, $from, -$quantity, StockMovementType::TransferOut, $transfer, $user, "{$transfer->number} to {$to->name}", $fromLot);
                $this->ledger->record($product, $to, $quantity, StockMovementType::TransferIn, $transfer, $user, "{$transfer->number} from {$from->name}", $toLot, $out->unit_cost_cents);
            }

            if ($transfer->lines()->doesntExist()) {
                OrderRules::fail('Add something to move.', 'lines');
            }

            return $transfer;
        });
    }
}
