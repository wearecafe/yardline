<?php

namespace App\Imports\Importers;

use App\Enums\DiscountType;
use App\Imports\ImportContext;
use App\Imports\Importer;
use App\Imports\Row;
use App\Models\PriceList;
use App\Models\PriceListCategoryRule;
use App\Models\PriceListItem;
use App\Models\ProductUnit;

/**
 * Price lists, by name: a row with a SKU sets that product's price on the
 * list (for one of its units, from a quantity); a row with a category sets
 * the list's discount on that category. A new name makes a new list.
 */
class PriceListsImporter implements Importer
{
    public function check(Row $row, ImportContext $context): void
    {
        $listName = $row->text('price_list', 120, required: true, label: 'price list');
        $sku = $row->text('sku', 64);
        $category = $row->text('category', 120);

        if ($listName === null) {
            return;
        }

        $list = $context->priceList($listName);

        $starts = $row->date('starts_on');
        $ends = $row->date('ends_on');

        if ($starts !== null && $ends !== null && $ends->lessThan($starts)) {
            $row->error('ends_on', 'Ends on has to be on or after starts on.');
        }

        if (($sku === null) === ($category === null)) {
            $row->error($sku === null ? 'sku' : 'category', 'Fill in either sku (a price on one product) or category (a discount off a category), not both.');

            return;
        }

        $common = ['list' => $listName, 'starts_on' => $starts?->toDateString(), 'ends_on' => $ends?->toDateString()];

        if ($sku !== null) {
            $this->checkPrice($row, $context, $list, $sku, $common);
        } else {
            $this->checkCategory($row, $context, $list, (string) $category, $common);
        }
    }

    /**
     * @param  array<string, mixed>  $common
     */
    private function checkPrice(Row $row, ImportContext $context, ?PriceList $list, string $sku, array $common): void
    {
        $product = $context->product($sku);

        if ($product === null) {
            $row->error('sku', "{$sku} isn’t a product. Import products first.");

            return;
        }

        $unitName = $row->text('unit', 60);
        $unit = $unitName === null ? null : $product->units->first(fn (ProductUnit $u) => ImportContext::key($u->name) === ImportContext::key($unitName)
            || ($u->is_base && ProductsImporter::unit($unitName) === $product->unit));

        if ($unitName !== null && $unit === null) {
            $row->error('unit', "{$sku} doesn’t sell in {$unitName}. It sells in ".$product->units->pluck('name')->implode(', ').'.');
        }

        $minimum = $row->number('min_quantity', min: 0, exclusive: true) ?? '1';
        $price = $row->money('price', required: true);
        // Base-unit prices are kept without a unit, as on the price list screen.
        $unitId = $unit !== null && ! $unit->is_base ? $unit->id : null;

        if ($context->planned('price', "{$common['list']}|{$product->id}|{$unitId}|{$minimum}|{$common['starts_on']}")) {
            $row->error('sku', 'This price is already on a row above.');

            return;
        }

        $context->plan('price', "{$common['list']}|{$product->id}|{$unitId}|{$minimum}|{$common['starts_on']}");

        $row->data = [...$common, 'product_id' => $product->id, 'product_unit_id' => $unitId, 'min_quantity' => $minimum, 'price_cents' => $price];
        $row->action = $list !== null && $this->item($list, $row->data) !== null ? Row::UPDATE : Row::CREATE;
    }

    /**
     * @param  array<string, mixed>  $common
     */
    private function checkCategory(Row $row, ImportContext $context, ?PriceList $list, string $category, array $common): void
    {
        $percent = $row->number('discount_percent', min: 0, exclusive: true);
        $amount = $row->money('discount_amount');

        if (($percent === null) === ($amount === null || $amount === 0)) {
            $row->error('discount_percent', 'Fill in either discount_percent or discount_amount.');
        } elseif ($percent !== null && (float) $percent > 100) {
            $row->error('discount_percent', 'Use a percent of 100 or less.');
        }

        if ($context->planned('category', "{$common['list']}|{$category}")) {
            $row->error('category', "{$category} already has a discount on a row above.");

            return;
        }

        $context->plan('category', "{$common['list']}|{$category}");

        $row->data = [
            ...$common,
            'category' => $category,
            'discount_type' => $percent !== null ? DiscountType::Percent->value : DiscountType::Amount->value,
            'discount_percent' => $percent,
            'discount_cents' => $percent !== null ? null : $amount,
        ];
        $row->action = $list !== null && $this->rule($list, $category) !== null ? Row::UPDATE : Row::CREATE;
    }

    public function apply(Row $row, ImportContext $context): string
    {
        $data = $row->data;
        $list = $context->priceList($data['list']) ?? PriceList::query()->create(['name' => $data['list']]);

        if (isset($data['category'])) {
            $rule = $this->rule($list, $data['category']) ?? new PriceListCategoryRule(['price_list_id' => $list->id]);
            $new = ! $rule->exists;
            $rule->fill([
                'category' => $data['category'],
                'discount_type' => $data['discount_type'],
                'discount_percent' => $data['discount_percent'],
                'discount_cents' => $data['discount_cents'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
            ])->save();

            return $new ? Row::CREATE : Row::UPDATE;
        }

        $item = $this->item($list, $data);

        if ($item !== null) {
            $item->fill(['price_cents' => $data['price_cents'], 'ends_on' => $data['ends_on']])->save();

            return Row::UPDATE;
        }

        $list->items()->create([
            'product_id' => $data['product_id'],
            'product_unit_id' => $data['product_unit_id'],
            'min_quantity' => $data['min_quantity'],
            'price_cents' => $data['price_cents'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
        ]);

        return Row::CREATE;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function item(PriceList $list, array $data): ?PriceListItem
    {
        return $list->items()
            ->where('product_id', $data['product_id'])
            ->where('product_unit_id', $data['product_unit_id'])
            ->where('min_quantity', $data['min_quantity'])
            ->where('starts_on', $data['starts_on'])
            ->first();
    }

    private function rule(PriceList $list, string $category): ?PriceListCategoryRule
    {
        return $list->categoryRules()->whereRaw('lower(trim(category)) = ?', [ImportContext::key($category)])->first();
    }
}
