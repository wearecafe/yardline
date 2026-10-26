<?php

namespace App\Pricing;

use App\Models\Organization;
use App\Models\PriceListItem;
use App\Models\Product;

/**
 * Yardline is the source of truth for price. Resolution order:
 *  1. The organization's price list: the tier with the highest min_quantity
 *     that the ordered quantity reaches.
 *  2. The product's list price.
 */
class PriceResolver
{
    public function unitPrice(Product $product, ?Organization $organization, string|int|float $quantity = 1): ResolvedPrice
    {
        if ($organization?->price_list_id !== null) {
            $item = PriceListItem::query()
                ->where('price_list_id', $organization->price_list_id)
                ->where('product_id', $product->id)
                ->where('min_quantity', '<=', $quantity)
                ->orderByDesc('min_quantity')
                ->first();

            if ($item !== null) {
                return new ResolvedPrice($item->price_cents, 'price_list', $item->price_list_id);
            }
        }

        return new ResolvedPrice($product->price_cents, 'list');
    }
}
