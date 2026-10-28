<?php

namespace App\Imports\Importers;

use App\Enums\StockMovementType;
use App\Imports\ImportContext;
use App\Imports\Importer;
use App\Imports\Row;
use App\Inventory\InventoryLedger;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockLot;
use App\Support\Money;

/**
 * What's on hand at each yard to start with. The quantity sets on-hand
 * (for a colour run, that run's on-hand); it doesn't add to it, so the
 * same file can be loaded again. Stock coming in is recorded as received
 * at its cost, so it sets the yard's average cost and stays out of the
 * profit report's shrinkage.
 */
class StockImporter implements Importer
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function check(Row $row, ImportContext $context): void
    {
        $sku = $row->text('sku', 64, required: true, label: 'SKU');
        $yardCell = $row->text('yard', 120, required: true);
        $product = $sku === null ? null : $context->product($sku);
        $yard = $yardCell === null ? null : $context->yard($yardCell);

        if ($sku !== null && $product === null) {
            $row->error('sku', "{$sku} isn’t a product. Import products first.");
        } elseif ($product !== null && ! $product->track_inventory) {
            $row->error('sku', "{$sku} doesn’t track stock. Turn on Track inventory for it first.");
        }

        if ($yardCell !== null && $yard === null) {
            $row->error('yard', "There’s no yard called {$yardCell}.");
        }

        $quantity = $row->number('quantity', required: true);
        $lot = $row->text('lot', 40);

        if ($lot !== null && $product !== null && ! $product->track_lots) {
            $row->error('lot', "{$sku} doesn’t track colour runs. Leave lot blank, or turn on colour runs for it.");
        }

        $row->data = [
            'product_id' => $product?->id,
            'location_id' => $yard?->id,
            'quantity' => $quantity,
            'unit_cost_cents' => $row->money('unit_cost'),
            'lot' => $lot,
            'bin' => $row->text('bin', 40),
            'reorder_point' => $row->number('reorder_point'),
        ];

        if ($product === null || $yard === null) {
            return;
        }

        if ($context->planned('stock', "{$product->id}|{$yard->id}|{$lot}")) {
            $row->error('sku', "{$sku} at {$yard->name}".($lot ? " ({$lot})" : '').' is already on a row above.');

            return;
        }

        $context->plan('stock', "{$product->id}|{$yard->id}|{$lot}");
        $row->action = StockLevel::query()->where('product_id', $product->id)->where('location_id', $yard->id)->exists() ? Row::UPDATE : Row::CREATE;
    }

    public function apply(Row $row, ImportContext $context): string
    {
        $data = $row->data;
        $product = Product::query()->findOrFail($data['product_id']);
        $yard = Location::query()->findOrFail($data['location_id']);
        $existed = StockLevel::query()->where('product_id', $product->id)->where('location_id', $yard->id)->exists();

        $level = StockLevel::query()->firstOrCreate(['product_id' => $product->id, 'location_id' => $yard->id]);
        $level->fill(array_filter(['bin' => $data['bin'], 'reorder_point' => $data['reorder_point']], fn ($v) => $v !== null))->save();

        $lot = $data['lot'] !== null
            ? StockLot::query()->firstOrCreate(
                ['product_id' => $product->id, 'location_id' => $yard->id, 'code' => $data['lot']],
                ['received_on' => now()->toDateString()],
            )
            : null;

        $current = $lot !== null ? $lot->refresh()->on_hand : $level->refresh()->on_hand;
        $deltaMilli = Money::toMilli($data['quantity']) - Money::toMilli($current);

        if ($deltaMilli !== 0) {
            $this->ledger->record(
                $product,
                $yard,
                number_format($deltaMilli / 1000, 3, '.', ''),
                $deltaMilli > 0 ? StockMovementType::Receipt : StockMovementType::Adjustment,
                reference: $context->import,
                user: $context->user,
                note: 'Opening stock',
                lot: $lot,
                unitCostCents: $data['unit_cost_cents'],
            );
        }

        return $existed ? Row::UPDATE : Row::CREATE;
    }
}
