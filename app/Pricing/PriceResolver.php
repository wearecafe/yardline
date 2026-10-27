<?php

namespace App\Pricing;

use App\Models\ContractPrice;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListCategoryRule;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductUnit;
use Carbon\CarbonImmutable;

/**
 * Yardline is the source of truth for price. For a product sold in a unit
 * to an organization on a date, the first of these that applies wins:
 *
 *  1. Products exempt from price lists sell at the unit's retail price.
 *  2. A contract price agreed with the organization, in effect that day.
 *  3. The organization's price list, which has two kinds of rule:
 *     - product entries: of those in effect that day, the tier with the
 *       highest min_quantity the quantity reaches;
 *     - a category rule: $ or % off everything in the product's category.
 *     When both match, the list's override setting (or the tenant default)
 *     picks one; they never add up, except that a list can stack its
 *     category discount on a volume-break price (an entry for more than 1).
 *  4. The unit's retail price.
 *
 * Contract and price list entries are per unit. An entry without a unit is
 * for the base unit and is converted for other units by their factor.
 */
class PriceResolver
{
    public function unitPrice(
        Product $product,
        ?Organization $organization,
        string|int|float $quantity = 1,
        ?ProductUnit $unit = null,
        ?CarbonImmutable $on = null,
    ): ResolvedPrice {
        $unit ??= $product->baseUnit;
        $on ??= CarbonImmutable::today();
        $factor = $unit !== null ? (float) $unit->factor : 1.0;
        $isBase = $unit === null || $unit->is_base;
        $retail = $unit !== null ? $unit->price_cents : $product->price_cents;

        if ($product->price_list_exempt) {
            return new ResolvedPrice($retail, 'exempt');
        }

        if ($organization !== null) {
            $contract = ContractPrice::query()
                ->where('organization_id', $organization->id)
                ->where('product_id', $product->id)
                ->inEffect($on)
                ->where(fn ($q) => $q->where('product_unit_id', $unit?->id)->when($isBase, fn ($q) => $q->orWhereNull('product_unit_id')))
                ->latest('id')
                ->first()
                ?? ($isBase ? null : ContractPrice::query()
                    ->where('organization_id', $organization->id)
                    ->where('product_id', $product->id)
                    ->whereNull('product_unit_id')
                    ->inEffect($on)
                    ->latest('id')
                    ->first());

            if ($contract !== null) {
                return new ResolvedPrice($this->convert($contract->price_cents, $contract->product_unit_id, $unit, $factor), 'contract', contractPriceId: $contract->id);
            }
        }

        $priceList = $organization?->price_list_id !== null ? PriceList::query()->find($organization->price_list_id) : null;

        if ($priceList !== null) {
            $baseQuantity = (float) $quantity * $factor;
            $entries = PriceListItem::query()
                ->where('price_list_id', $priceList->id)
                ->where('product_id', $product->id)
                ->inEffect($on)
                ->get();

            // Entries for this unit, by quantity in this unit; otherwise base-unit entries, by base quantity.
            $own = $unit !== null && ! $isBase ? $entries->where('product_unit_id', $unit->id) : collect();
            $item = $own->isNotEmpty()
                ? $own->filter(fn (PriceListItem $i) => (float) $i->min_quantity <= (float) $quantity + 1e-9)->sortByDesc(fn ($i) => $this->rank($i))->first()
                : $entries->filter(fn (PriceListItem $i) => $i->product_unit_id === null || $i->product_unit_id === $product->baseUnit?->id)
                    ->filter(fn (PriceListItem $i) => (float) $i->min_quantity <= $baseQuantity + 1e-9)
                    ->sortByDesc(fn ($i) => $this->rank($i))
                    ->first();
            $itemCents = $item !== null ? $this->convert($item->price_cents, $own->isNotEmpty() ? $item->product_unit_id : null, $unit, $factor) : null;
            $rule = $this->categoryRule($priceList, $product, $on);

            if ($rule !== null && $item !== null && $priceList->stack_volume_breaks && (float) $item->min_quantity > 1) {
                return new ResolvedPrice($rule->apply((int) $itemCents, $factor), 'volume+category', $priceList->id, categoryRuleId: $rule->id);
            }

            if ($rule !== null && ($item === null || $priceList->categoryRulesWin())) {
                return new ResolvedPrice($rule->apply($retail, $factor), 'category', $priceList->id, categoryRuleId: $rule->id);
            }

            if ($item !== null) {
                return new ResolvedPrice((int) $itemCents, 'price_list', $priceList->id);
            }
        }

        return new ResolvedPrice($retail, 'list');
    }

    /**
     * The list's rule for the product's category, matched without regard
     * to case or surrounding spaces.
     */
    private function categoryRule(PriceList $priceList, Product $product, CarbonImmutable $on): ?PriceListCategoryRule
    {
        $category = mb_strtolower(trim((string) $product->category));

        if ($category === '') {
            return null;
        }

        return PriceListCategoryRule::query()
            ->where('price_list_id', $priceList->id)
            ->whereRaw('lower(trim(category)) = ?', [$category])
            ->inEffect($on)
            ->first();
    }

    /**
     * Higher quantity breaks first; at the same break, a dated promotion
     * beats the everyday price.
     *
     * @return array{0: float, 1: int}
     */
    private function rank(PriceListItem $item): array
    {
        return [(float) $item->min_quantity, $item->starts_on !== null || $item->ends_on !== null ? 1 : 0];
    }

    /**
     * A base-unit price converted to the unit being sold.
     */
    private function convert(int $cents, ?int $priceUnitId, ?ProductUnit $unit, float $factor): int
    {
        if ($unit === null || $unit->is_base || $priceUnitId === $unit->id) {
            return $cents;
        }

        return (int) round($cents * $factor);
    }
}
