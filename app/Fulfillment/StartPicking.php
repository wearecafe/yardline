<?php

namespace App\Fulfillment;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Sequence;
use App\Models\Shipment;
use App\Models\User;
use App\Orders\OrderRules;
use Illuminate\Support\Facades\DB;

/**
 * Starts a pick list for what's left to ship on an order. Pass quantities
 * per order line to ship part of it now.
 */
class StartPicking
{
    /**
     * @param  array<int, string|float>|null  $quantities  order line id => quantity
     */
    public function handle(Order $order, User $user, ?User $picker = null, ?array $quantities = null): Shipment
    {
        if ($order->status !== OrderStatus::Confirmed) {
            OrderRules::fail('Only confirmed orders can be picked.');
        }

        if ($order->shipments()->inYard()->exists()) {
            OrderRules::fail('This order is already being picked. Finish that pick list first.');
        }

        $lines = $order->lines()->orderBy('position')->orderBy('id')->get()
            ->filter(fn ($line) => $line->remainingQuantity() > 0);

        if ($lines->isEmpty()) {
            OrderRules::fail('Everything on this order has shipped.');
        }

        return DB::transaction(function () use ($order, $user, $picker, $quantities, $lines) {
            $shipment = $order->shipments()->create([
                'number' => 'S-'.Sequence::next('shipments'),
                'method' => $order->fulfillment,
                'status' => ShipmentStatus::Picking,
                'picker_id' => $picker?->id,
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $quantity = $quantities === null
                    ? $line->remainingQuantity()
                    : min($line->remainingQuantity(), (float) ($quantities[$line->id] ?? 0));

                if ($quantity > 0) {
                    $shipment->lines()->create(['order_line_id' => $line->id, 'quantity' => $quantity]);
                }
            }

            if ($shipment->lines()->doesntExist()) {
                OrderRules::fail('Pick at least one item.');
            }

            $order->forceFill(['fulfillment_status' => 'picking'])->save();

            return $shipment;
        });
    }
}
