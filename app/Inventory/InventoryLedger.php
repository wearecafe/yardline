<?php

namespace App\Inventory;

use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way stock changes. Every change writes a movement row and updates
 * the running on-hand in the same transaction.
 */
class InventoryLedger
{
    public function record(
        Product $product,
        Location $location,
        string|int|float $quantity,
        StockMovementType $type,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $type, $reference, $user, $note) {
            $level = $this->lockLevel($product, $location);

            $level->newQuery()->whereKey($level->getKey())->increment('on_hand', (float) $quantity);

            return StockMovement::query()->create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'quantity' => $quantity,
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
