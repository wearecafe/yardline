<?php

namespace App\Imports\Importers;

use App\Enums\Unit;
use App\Imports\ImportContext;
use App\Imports\Importer;
use App\Imports\Row;
use App\Models\Product;
use App\Models\ProductUnit;

/**
 * Products and their selling units. A product's own row (Unit blank, or
 * its base unit) sets up the product; each other row for the same SKU
 * adds or updates one selling unit. Products are matched by SKU; blank
 * cells leave what's already there.
 */
class ProductsImporter implements Importer
{
    /** @var array<string, Unit> */
    private const UNITS = [
        'each' => Unit::Each, 'ea' => Unit::Each, 'piece' => Unit::Each, 'pc' => Unit::Each, 'unit' => Unit::Each,
        'pallet' => Unit::Pallet, 'pallets' => Unit::Pallet, 'plt' => Unit::Pallet, 'skid' => Unit::Pallet,
        'cube' => Unit::Cube, 'cubes' => Unit::Cube,
        'bag' => Unit::Bag, 'bags' => Unit::Bag,
        'bundle' => Unit::Bundle, 'bundles' => Unit::Bundle, 'bdl' => Unit::Bundle,
        'tonne' => Unit::Tonne, 'tonnes' => Unit::Tonne, 'ton' => Unit::Tonne, 'tons' => Unit::Tonne, 't' => Unit::Tonne, 'mt' => Unit::Tonne,
        'cubic_yard' => Unit::CubicYard, 'cu yd' => Unit::CubicYard, 'cubic yard' => Unit::CubicYard, 'cubic yards' => Unit::CubicYard, 'yard' => Unit::CubicYard, 'yd' => Unit::CubicYard, 'cy' => Unit::CubicYard, 'yd3' => Unit::CubicYard,
        'sq_ft' => Unit::SquareFoot, 'sq ft' => Unit::SquareFoot, 'sqft' => Unit::SquareFoot, 'sf' => Unit::SquareFoot, 'square foot' => Unit::SquareFoot, 'square feet' => Unit::SquareFoot, 'ft2' => Unit::SquareFoot,
        'lin_ft' => Unit::LinearFoot, 'lin ft' => Unit::LinearFoot, 'lf' => Unit::LinearFoot, 'linear foot' => Unit::LinearFoot, 'linear feet' => Unit::LinearFoot,
    ];

    public static function unit(?string $value): ?Unit
    {
        return $value === null ? null : (self::UNITS[ImportContext::key($value)] ?? null);
    }

    public function check(Row $row, ImportContext $context): void
    {
        $sku = $row->text('sku', 64, required: true, label: 'SKU');

        if ($sku === null) {
            return;
        }

        $product = $context->product($sku);
        $unitName = $row->text('unit', 60);
        $baseUnit = $product->unit ?? self::unit($row->text('base_unit', 30));
        $isProductRow = $unitName === null || ($baseUnit !== null && self::unit($unitName) === $baseUnit);

        $isProductRow ? $this->checkProduct($row, $context, $sku, $product) : $this->checkUnit($row, $context, $sku, $product, $unitName);
    }

    private function checkProduct(Row $row, ImportContext $context, string $sku, ?Product $product): void
    {
        if ($context->planned('product_row', $sku)) {
            $row->error('sku', "{$sku} already has its own row above. Give each other unit its own Unit.");

            return;
        }

        $context->plan('product_row', $sku);
        $new = $product === null;
        $baseUnitCell = $row->text('base_unit', 30);
        $baseUnit = self::unit($baseUnitCell);

        if ($baseUnitCell !== null && $baseUnit === null) {
            $row->error('base_unit', 'Use each, bag, pallet, cube, bundle, tonne, cu yd, sq ft or lin ft.');
        } elseif ($new && $baseUnit === null) {
            $row->error('base_unit', 'Base unit is required for a new product.');
        } elseif (! $new && $baseUnit !== null && $baseUnit !== $product->unit) {
            $row->error('base_unit', "{$sku} is counted in {$product->unit->label()}. The base unit of an existing product can’t change here.");
        }

        $row->data = array_filter([
            'sku' => $sku,
            'name' => $row->text('name', 255, required: $new),
            'unit' => $baseUnit,
            'price_cents' => $row->money('price', required: $new),
            'category' => $row->text('category', 120),
            'cost_cents' => $row->money('cost'),
            'taxable' => $row->bool('taxable'),
            'tax_code' => $row->text('tax_code', 40),
            'track_inventory' => $row->bool('track_inventory'),
            'per_pallet' => $row->number('per_pallet', min: 0, exclusive: true),
            'weight_kg' => $row->number('weight_kg'),
            'pallet_deposit' => $row->bool('pallet_deposit'),
            'pallet_usage_fee' => $row->bool('pallet_usage_fee'),
            'price_list_exempt' => $row->bool('price_list_exempt'),
            'is_active' => $row->bool('active'),
            'description' => $row->text('description', 2000),
        ], fn ($value) => $value !== null);

        $row->action = $new ? Row::CREATE : Row::UPDATE;

        if ($new && $row->ok()) {
            $context->plan('product', $sku);
        }
    }

    private function checkUnit(Row $row, ImportContext $context, string $sku, ?Product $product, string $unitName): void
    {
        if ($product === null && ! $context->planned('product', $sku)) {
            $row->error('sku', "{$sku} isn’t a product yet. Its own row, with Unit blank, has to come first.");

            return;
        }

        if ($context->planned('unit_row', "{$sku}|{$unitName}")) {
            $row->error('unit', "{$sku} already has a {$unitName} row above.");

            return;
        }

        $context->plan('unit_row', "{$sku}|{$unitName}");

        $row->data = [
            'sku' => $sku,
            'name' => $unitName,
            'factor' => $row->number('per_base', required: true, exclusive: true),
            'price_cents' => $row->money('price', required: true),
            'sellable' => $row->bool('sellable', true),
        ];

        $existing = $product?->units->first(fn (ProductUnit $u) => ImportContext::key($u->name) === ImportContext::key($unitName));
        $row->action = $existing !== null ? Row::UPDATE : Row::CREATE;
    }

    public function apply(Row $row, ImportContext $context): string
    {
        $data = $row->data;
        $product = $context->product($data['sku']);

        if (array_key_exists('factor', $data)) {
            return $this->applyUnit($data, $product);
        }

        if ($product === null) {
            Product::query()->create($data);

            return Row::CREATE;
        }

        unset($data['sku']);
        $product->fill($data)->save();

        return Row::UPDATE;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyUnit(array $data, ?Product $product): string
    {
        if ($product === null) {
            return Row::SKIP; // its product row failed
        }

        $unit = $product->units()->get()->first(fn (ProductUnit $u) => ImportContext::key($u->name) === ImportContext::key($data['name']));
        $values = ['factor' => $data['factor'], 'price_cents' => $data['price_cents'], 'sellable' => $data['sellable']];

        if ($unit !== null) {
            $unit->fill($unit->is_base ? ['price_cents' => $data['price_cents']] : $values)->save();

            return Row::UPDATE;
        }

        $product->units()->create([
            'name' => $data['name'],
            ...$values,
            'position' => (int) $product->units()->max('position') + 1,
        ]);

        return Row::CREATE;
    }
}
