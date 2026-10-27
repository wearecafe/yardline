<?php

namespace App\Fulfillment;

use App\Audit\Audit;
use App\Enums\OrderStatus;
use App\Enums\Settlement;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementType;
use App\Enums\StopStatus;
use App\Inventory\InventoryLedger;
use App\Models\Shipment;
use App\Models\StockLot;
use App\Models\User;
use App\Orders\OrderRules;
use App\Receivables\PalletLedger;
use Illuminate\Support\Facades\DB;

/**
 * The load is on the truck (delivery) or set out at the yard (pickup).
 *
 * Marking it loaded goes through the yard's stages: from Picking it passes
 * through Picked, and if the business checks loads it waits at Loaded for
 * a sign-off. Once nothing is left to check it's released: stock leaves
 * by what was actually picked, anything short stays open on the order
 * for another shipment, and a delivery goes to the dispatch board.
 */
class LoadShipment
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function handle(Shipment $shipment, User $user): Shipment
    {
        $settings = PickingSettings::current();

        if ($shipment->isReleased() || $shipment->status->isDone() || $shipment->status === ShipmentStatus::Loaded) {
            OrderRules::fail('This load has already gone.');
        }

        if ($shipment->awaitingCheck()) {
            OrderRules::fail('This load is waiting for a quality check.');
        }

        // Straight from Picking means Picked is done too, unless it has to be checked.
        if ($shipment->status === ShipmentStatus::Picking && $settings->requiresCheck('picked')) {
            OrderRules::fail('Mark it picked first so it can be checked.');
        }

        $this->ensurePaid($shipment);

        return DB::transaction(function () use ($shipment, $user, $settings) {
            if ($shipment->status === ShipmentStatus::Picking) {
                $shipment->forceFill(['picked_at' => now(), 'picked_by' => $user->id]);
            }

            $shipment->forceFill([
                'loaded_at' => now(),
                'loaded_by' => $user->id,
                'picker_id' => $shipment->picker_id ?? $user->id,
            ]);

            if ($settings->requiresCheck('loaded')) {
                $shipment->forceFill(['status' => ShipmentStatus::Loaded, 'check_stage' => 'loaded'])->save();
                Audit::record($shipment, 'loaded', note: 'Waiting for a quality check.');

                return $shipment;
            }

            Audit::record($shipment, 'loaded');

            return $this->release($shipment, $user);
        });
    }

    /**
     * Stock leaves the yard's books and the load goes to dispatch (or waits
     * for the customer). Called once every stage the business checks has
     * been signed off.
     */
    public function release(Shipment $shipment, User $user): Shipment
    {
        $order = $shipment->order;

        if ($shipment->isReleased()) {
            OrderRules::fail('This load has already gone.');
        }

        $this->ensurePaid($shipment);
        $loadedStage = PickingSettings::current()->stageOn('loaded');

        return DB::transaction(function () use ($shipment, $order, $user, $loadedStage) {
            $shipment->load('lines.orderLine.product', 'lines.orderLine.productUnit', 'lines.lot');
            $weight = 0.0;

            foreach ($shipment->lines as $line) {
                $orderLine = $line->orderLine;
                $quantity = $line->loadedQuantity();

                // Colour-run stock comes out of a run.
                if ($line->stock_lot_id === null && $orderLine->product?->track_lots) {
                    $lot = StockLot::bestFor((int) $orderLine->product_id, $order->location_id);
                    $line->forceFill(['stock_lot_id' => $lot?->id])->save();
                    $line->setRelation('lot', $lot);
                }
                $base = $quantity * (float) ($orderLine->unit_factor ?: 1);
                $product = $orderLine->product;

                if ($product?->track_inventory && $base > 0) {
                    $this->ledger->record($product, $order->location, -1 * $base, StockMovementType::Sale, $shipment, $user, lot: $line->lot);
                }

                $weight += $base * (float) ($product->weight_kg ?? 0);

                // Never ship more than was ordered on the books.
                $orderLine->shipped_quantity = (string) min((float) $orderLine->quantity, (float) $orderLine->shipped_quantity + $quantity);
                $orderLine->save();
            }

            app(PalletLedger::class)->shipped($shipment, $user);

            // With the Loaded stage off, a delivery waits for its truck as
            // "ready for dispatch" rather than "loaded".
            $shipment->forceFill([
                'status' => $shipment->isDelivery() && $loadedStage ? ShipmentStatus::Loaded : ShipmentStatus::Ready,
                'check_stage' => null,
                'picked_at' => $shipment->picked_at ?? now(),
                'loaded_at' => $shipment->loaded_at ?? now(),
                'released_at' => now(),
                'weight_kg' => round($weight, 1),
                'picker_id' => $shipment->picker_id ?? $user->id,
            ])->save();

            // The run planned for the order now carries this load.
            $planned = $shipment->isDelivery()
                ? $order->stops()->whereNotIn('status', [StopStatus::Done->value, StopStatus::Cancelled->value])->first()
                : null;

            if ($planned !== null) {
                $planned->forceFill(['stoppable_type' => $shipment->getMorphClass(), 'stoppable_id' => $shipment->id])->save();
            } elseif ($shipment->isDelivery() && $shipment->stop()->doesntExist()) {
                $shipment->stop()->create([
                    'kind' => 'delivery',
                    'status' => StopStatus::Unscheduled,
                    'date' => $order->requested_on?->toDateString(),
                    'address' => $order->delivery_address,
                    'needs' => $order->needs_boom ? 'Needs boom truck' : null,
                    'duration_minutes' => 60,
                ]);
            }

            $open = $order->lines()->get()->contains(fn ($l) => $l->remainingQuantity() > 0);

            $order->forceFill([
                'fulfillment_status' => $open ? 'partly_shipped' : 'shipped',
                'status' => $open ? $order->status : OrderStatus::Fulfilled,
                'fulfilled_at' => $open ? $order->fulfilled_at : now(),
            ])->save();

            return $shipment;
        });
    }

    private function ensurePaid(Shipment $shipment): void
    {
        $order = $shipment->order;

        if ($order->settlement === Settlement::Prepaid && $order->balanceCents() > 0) {
            OrderRules::fail('Take payment before releasing this order.');
        }
    }
}
