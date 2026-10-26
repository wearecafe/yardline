<?php

namespace App\Fulfillment;

use App\Enums\ShipmentStatus;
use App\Models\ShipmentLine;
use App\Models\StockLot;
use App\Orders\OrderRules;

/**
 * The picker confirms a line: the quantity loaded (a scale reading for
 * weighed material), or that it was short.
 */
class RecordPick
{
    /** Weighed loads within this share of the order are fine. */
    public const TOLERANCE = 0.05;

    public function handle(ShipmentLine $line, string|float $picked, bool $short = false, ?string $note = null): ShipmentLine
    {
        if ($line->shipment->status !== ShipmentStatus::Picking) {
            OrderRules::fail('This pick list has already been loaded.');
        }

        $picked = round((float) $picked, 3);

        if ($picked < 0) {
            OrderRules::fail('The quantity can’t be negative.', 'picked_quantity');
        }

        // A short pick ships less now; the rest stays open on the order.
        if (! $short && $picked < (float) $line->quantity * (1 - self::TOLERANCE)) {
            $short = true;
        }

        // A colour-run product ships from one run: the one with the most on hand.
        $orderLine = $line->orderLine;
        $lotId = $line->stock_lot_id;
        if ($lotId === null && $orderLine->product?->track_lots) {
            $lotId = StockLot::bestFor((int) $orderLine->product_id, $line->shipment->order->location_id)?->id;
        }

        $line->forceFill([
            'stock_lot_id' => $lotId,
            'picked_quantity' => $picked,
            'short' => $short,
            'note' => $note,
            'picked_at' => now(),
        ])->save();

        return $line;
    }

    public static function withinTolerance(float $ordered, float $picked): bool
    {
        return $ordered <= 0 || abs($picked - $ordered) / $ordered <= self::TOLERANCE;
    }
}
