<?php

namespace App\Inventory;

use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way stock changes. Every change writes a movement row and updates
 * the running on-hand in the same transaction.
 *
 * It also keeps the average cost of what's on hand at each yard (cents per
 * base unit). Stock that comes in at a known cost (a receipt, a transfer
 * from another yard, a return put back on the shelf) blends into the
 * average; everything else moves at the average. Each movement records the
 * cost it moved at, so the profit report can value shrinkage and counts.
 */
class InventoryLedger
{
    /** Movements that bring stock in at their own cost. */
    private const COSTED_IN = [
        StockMovementType::Receipt,
        StockMovementType::TransferIn,
        StockMovementType::CustomerReturn,
    ];

    /**
     * @param  string|int|float|null  $unitCostCents  cost per base unit of stock coming in;
     *                                                a receipt without one comes in at the product's cost
     */
    public function record(
        Product $product,
        Location $location,
        string|int|float $quantity,
        StockMovementType $type,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
        ?StockLot $lot = null,
        string|int|float|null $unitCostCents = null,
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $type, $reference, $user, $note, $lot, $unitCostCents) {
            $level = $this->lockLevel($product, $location);
            $cost = $this->cost($level, $product, (float) $quantity, $type, $unitCostCents);

            $level->newQuery()->whereKey($level->getKey())->increment('on_hand', (float) $quantity);

            if ($lot !== null) {
                $lot->newQuery()->whereKey($lot->getKey())->increment('on_hand', (float) $quantity);
            }

            return StockMovement::query()->create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'stock_lot_id' => $lot?->id,
                'quantity' => $quantity,
                'unit_cost_cents' => $cost !== null ? number_format($cost, 4, '.', '') : null,
                'type' => $type,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $user?->id,
                'note' => $note,
            ]);
        });
    }

    /**
     * Set on-hand to a counted quantity, recording the difference.
     */
    public function count(Product $product, Location $location, string|int|float $counted, ?User $user = null, ?string $note = null): ?StockMovement
    {
        return DB::transaction(function () use ($product, $location, $counted, $user, $note) {
            $level = $this->lockLevel($product, $location);

            $deltaMilli = Money::toMilli($counted) - Money::toMilli($level->on_hand);

            if ($deltaMilli === 0) {
                return null;
            }

            return $this->record(
                $product,
                $location,
                number_format($deltaMilli / 1000, 3, '.', ''),
                StockMovementType::Count,
                user: $user,
                note: $note,
            );
        });
    }

    /**
     * The cost this movement moves at, updating the yard's average when stock
     * comes in at its own cost. Runs with the level locked, before on-hand
     * changes.
     */
    private function cost(StockLevel $level, Product $product, float $quantity, StockMovementType $type, string|int|float|null $unitCostCents): ?float
    {
        $current = $level->average_cost_cents !== null
            ? (float) $level->average_cost_cents
            : ($product->cost_cents !== null ? (float) $product->cost_cents : null);

        $incoming = $quantity > 0 && in_array($type, self::COSTED_IN, true);

        if ($incoming && $unitCostCents === null && $type === StockMovementType::Receipt && $product->cost_cents !== null) {
            $unitCostCents = $product->cost_cents;
        }

        if (! $incoming || $unitCostCents === null) {
            if ($level->average_cost_cents === null && $current !== null) {
                $this->setAverage($level, $current);
            }

            return $current;
        }

        $incomingCost = (float) $unitCostCents;
        // Stock oversold below zero carries no cost into the blend.
        $held = max(0.0, (float) $level->on_hand);
        $average = $held > 0 && $current !== null
            ? ($held * $current + $quantity * $incomingCost) / ($held + $quantity)
            : $incomingCost;

        $this->setAverage($level, $average);

        return $incomingCost;
    }

    private function setAverage(StockLevel $level, float $average): void
    {
        $level->newQuery()->whereKey($level->getKey())->update([
            'average_cost_cents' => number_format($average, 4, '.', ''),
        ]);
    }

    public function onHand(Product $product, Location $location): string
    {
        return StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->value('on_hand') ?? '0.000';
    }

    private function lockLevel(Product $product, Location $location): StockLevel
    {
        StockLevel::query()->firstOrCreate([
            'product_id' => $product->id,
            'location_id' => $location->id,
        ]);

        return StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
