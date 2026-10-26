<?php

namespace App\Http\Presenters;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\StockLevel;
use App\Models\StockLot;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Cards for the order queue and pick lists for the picker.
 */
final class FulfillmentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function orderCard(Order $order, ?Shipment $shipment = null): array
    {
        $lines = $shipment !== null
            ? $shipment->lines->map(fn (ShipmentLine $l) => [$l->orderLine, (float) $l->quantity])
            : $order->lines->map(fn (OrderLine $l) => [$l, $l->remainingQuantity()])->filter(fn ($pair) => $pair[1] > 0);

        return [
            'key' => $shipment ? "s{$shipment->id}" : "o{$order->id}",
            'order_id' => $order->id,
            'shipment_id' => $shipment?->id,
            'shipment_number' => $shipment?->number,
            'number' => $order->number,
            'source' => $order->source,
            'time' => ($shipment->created_at ?? $order->confirmed_at ?? $order->created_at)->toIso8601String(),
            'customer' => $order->organization->name ?? ($order->customer ? 'Walk-in · '.$order->customer->full_name : 'Walk-in'),
            'summary' => $lines->map(fn ($pair) => self::qty($pair[1]).' '.self::unit($pair[0]).' '.mb_strtolower($pair[0]->description))->take(3)->implode(', ')
                .($lines->count() > 3 ? ', …' : ''),
            'mode' => $order->fulfillment->value === 'delivery'
                ? 'Delivery'.($order->deliveryZone ? ' · '.preg_replace('/\s*·.*$/', '', $order->deliveryZone->name) : '')
                : 'Pickup',
            'status' => $shipment?->status->value ?? 'new',
            'partial' => $order->fulfillment_status === 'partly_shipped',
            'picker' => $shipment?->picker?->name,
        ];
    }

    /**
     * @param  Collection<int, StockLevel>  $levels  keyed by product id, at the order's yard
     * @return array<string, mixed>
     */
    public static function pickList(Order $order, ?Shipment $shipment, Collection $levels): array
    {
        $rows = $shipment !== null
            ? $shipment->lines->map(fn (ShipmentLine $l) => ['line' => $l->orderLine, 'quantity' => (float) $l->quantity, 'shipment_line' => $l])
            : $order->lines->map(fn (OrderLine $l) => ['line' => $l, 'quantity' => $l->remainingQuantity(), 'shipment_line' => null])->filter(fn ($r) => $r['quantity'] > 0)->values();

        $weight = 0.0;
        $deposits = 0;
        $depositCount = 0;

        $items = $rows->map(function (array $row) use ($levels, &$weight, &$deposits, &$depositCount) {
            /** @var OrderLine $line */
            $line = $row['line'];
            $unit = $line->productUnit;
            $level = $line->product_id ? $levels->get($line->product_id) : null;
            $base = $row['quantity'] * (float) ($line->unit_factor ?: 1);
            $weight += $base * (float) ($line->product->weight_kg ?? 0);

            if ($unit !== null && $unit->deposit_cents > 0) {
                $deposits += (int) round($row['quantity'] * $unit->deposit_cents);
                $depositCount += (int) round($row['quantity']);
            }

            $measure = $unit->measured_by ?? null;
            $weighed = $measure === 'scale' || ($unit === null && in_array($line->unit, ['tonne'], true));
            $hint = match (true) {
                $weighed => 'load with the loader, then weigh',
                $measure === 'bucket' => 'loader buckets',
                default => null,
            };

            /** @var ShipmentLine|null $sl */
            $sl = $row['shipment_line'];

            return [
                'id' => $sl?->id,
                'order_line_id' => $line->id,
                'item' => $line->description,
                'sku' => $line->sku,
                'where' => trim(implode(' · ', array_filter([$level?->bin, self::lot($line, $sl, $levels), $hint]))) ?: null,
                'quantity' => number_format($row['quantity'], 3, '.', ''),
                'unit' => self::unit($line),
                'weighed' => $weighed,
                'picked_quantity' => $sl?->picked_quantity,
                'short' => (bool) $sl?->short,
                'note' => $sl?->note,
                'picked' => $sl?->picked_at !== null,
            ];
        })->values();

        return [
            'items' => $items,
            'weight_kg' => round($weight, 1),
            'deposits' => $depositCount > 0 ? ['count' => $depositCount, 'cents' => $deposits, 'label' => $depositCount.' × '.Money::format(intdiv($deposits, max(1, $depositCount)))] : null,
        ];
    }

    private static function unit(OrderLine $line): string
    {
        $labels = ['tonne' => 't', 'cubic_yard' => 'yd', 'sq_ft' => 'sq ft', 'lin_ft' => 'lin ft', 'each' => 'pcs', 'pallet' => 'plt', 'bag' => 'bags', 'cube' => 'cubes', 'bundle' => 'bundles'];

        return $labels[$line->unit] ?? mb_strtolower($line->unit);
    }

    private static function qty(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    /**
     * "run 2611-B only" for colour-run products.
     *
     * @param  Collection<int, StockLevel>  $levels
     */
    private static function lot(OrderLine $line, ?ShipmentLine $shipmentLine, Collection $levels): ?string
    {
        if (! $line->product?->track_lots) {
            return null;
        }

        $lot = $shipmentLine?->lot ?? ($levels->first() ? StockLot::bestFor((int) $line->product_id, (int) $levels->first()->location_id) : null);

        return $lot ? "{$lot->code} only" : null;
    }
}
