<?php

namespace App\Purchasing;

use App\Enums\StockMovementType;
use App\Inventory\InventoryLedger;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockLot;
use App\Models\User;
use App\Orders\OrderRules;
use Illuminate\Support\Facades\DB;

/**
 * A delivery from a supplier comes in against a PO: stock goes up (into a
 * colour run for lot-tracked products), and a new landed cost updates the
 * product and flags the change for a price review.
 */
class ReceivePurchaseOrder
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * @param  list<array{line_id: int, quantity: string|float, lot?: string|null, unit_cost_cents?: int|null}>  $received
     */
    public function handle(PurchaseOrder $po, array $received, User $user): PurchaseOrder
    {
        if (! $po->isOpen()) {
            OrderRules::fail('This purchase order is closed.');
        }

        $lines = $po->lines()->with('product')->get()->keyBy('id');

        return DB::transaction(function () use ($po, $received, $user, $lines) {
            $any = false;

            foreach ($received as $row) {
                $line = $lines->get((int) $row['line_id']);
                $quantity = round((float) $row['quantity'], 3);

                if ($line === null || $quantity <= 0) {
                    continue;
                }

                $product = $line->product;
                $lot = $this->lot($product, $po, $row['lot'] ?? null);

                $this->ledger->record($product, $po->location, $quantity, StockMovementType::Receipt, $po, $user, "{$po->number} · {$po->supplier->name}", $lot);

                $line->received_quantity = (string) ((float) $line->received_quantity + $quantity);

                if (! empty($row['unit_cost_cents'])) {
                    $line->unit_cost_cents = (int) $row['unit_cost_cents'];
                }

                $line->save();
                self::updateCost($product, $line->unit_cost_cents);
                $any = true;
            }

            if (! $any) {
                OrderRules::fail('Enter what came in.', 'lines');
            }

            $open = $po->lines()->get()->contains(fn ($l) => $l->outstanding() > 0);
            $po->forceFill([
                'status' => $open ? 'partial' : 'received',
                'received_at' => $open ? null : now(),
                'total_cents' => (int) $po->lines()->get()->sum(fn ($l) => (int) round((float) $l->quantity * $l->unit_cost_cents)),
            ])->save();

            return $po;
        });
    }

    public static function updateCost(Product $product, int $cents): void
    {
        if ($cents <= 0 || $product->cost_cents === $cents) {
            return;
        }

        $product->forceFill([
            'previous_cost_cents' => $product->cost_cents,
            'cost_cents' => $cents,
            'cost_changed_at' => $product->cost_cents !== null ? now() : $product->cost_changed_at,
        ])->save();
    }

    private function lot(Product $product, PurchaseOrder $po, ?string $code): ?StockLot
    {
        if (! $product->track_lots) {
            return null;
        }

        $code = trim((string) $code);

        if ($code === '') {
            OrderRules::fail("{$product->name} ships in colour runs. Enter the run on the tag.", 'lines');
        }

        return StockLot::query()->firstOrCreate(
            ['product_id' => $product->id, 'location_id' => $po->location_id, 'code' => $code],
            ['received_on' => now()->toDateString()],
        );
    }
}
