<?php

namespace App\Commerce\Shopify;

use App\Commerce\CommerceConnector;
use App\Enums\IntegrationProvider;
use App\Models\Customer;
use App\Models\ExternalReference;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Pushes Yardline products and customers to Shopify. Yardline controls price:
 * the Shopify variant price is a copy of the product's list price.
 *
 * Not yet built (see docs/shopify.md):
 *  - organizations → Shopify B2B companies (Shopify Plus)
 *  - price lists → B2B catalogs / price lists
 *  - inbound orders (webhook handler is stubbed)
 *  - inventory quantities per location
 */
class ShopifyConnector implements CommerceConnector
{
    private const PRODUCT_SET = <<<'GQL'
        mutation productSet($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
          productSet(input: $input, identifier: $identifier, synchronous: true) {
            product { id }
            userErrors { field message }
          }
        }
        GQL;

    private const CUSTOMER_CREATE = <<<'GQL'
        mutation customerCreate($input: CustomerInput!) {
          customerCreate(input: $input) {
            customer { id }
            userErrors { field message }
          }
        }
        GQL;

    private const CUSTOMER_UPDATE = <<<'GQL'
        mutation customerUpdate($input: CustomerInput!) {
          customerUpdate(input: $input) {
            customer { id }
            userErrors { field message }
          }
        }
        GQL;

    public function __construct(private readonly ShopifyClient $shopify) {}

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Shopify;
    }

    public function pushProduct(Product $product, ?ExternalReference $reference): ?string
    {
        // Rental equipment isn't sold online.
        if ($product->isRental()) {
            return $reference?->external_id;
        }

        // One variant per unit for sale; units switched off for sale (a base
        // unit only used to count stock) aren't offered in Shopify. Only the
        // base unit tracks stock there; other units convert back to it when
        // the order comes in.
        $units = $product->units()->where('sellable', true)->get();
        $names = $units->map(fn ($unit) => $unit->variant_title ?: $unit->name)->values();

        $variables = [
            'input' => [
                'title' => $product->name,
                'descriptionHtml' => e((string) $product->description),
                'productType' => (string) $product->category,
                'status' => $product->is_active ? 'ACTIVE' : 'DRAFT',
                'productOptions' => [
                    ['name' => 'Unit', 'values' => $names->map(fn ($name) => ['name' => $name])->all()],
                ],
                'variants' => $units->values()->map(fn ($unit, $i) => [
                    'optionValues' => [['optionName' => 'Unit', 'name' => $names[$i]]],
                    'sku' => $unit->is_base ? $product->sku : $product->sku.'-'.Str::upper(Str::slug($unit->name)),
                    'price' => number_format($unit->price_cents / 100, 2, '.', ''),
                    'inventoryItem' => ['tracked' => $unit->is_base && $product->track_inventory],
                ])->all(),
            ],
        ];

        if ($reference !== null) {
            $variables['identifier'] = ['id' => $reference->external_id];
        }

        $result = $this->shopify->mutate('productSet', self::PRODUCT_SET, $variables);

        return $result['product']['id'] ?? null;
    }

    public function pushCustomer(Customer $customer, ?ExternalReference $reference): ?string
    {
        if (blank($customer->email) && blank($customer->phone)) {
            return $reference?->external_id; // Shopify needs one of the two
        }

        $input = array_filter([
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'note' => $customer->organization ? "Yardline account: {$customer->organization->name}" : null,
        ], fn ($value) => filled($value));

        if ($reference !== null) {
            $result = $this->shopify->mutate('customerUpdate', self::CUSTOMER_UPDATE, [
                'input' => ['id' => $reference->external_id, ...$input],
            ]);
        } else {
            $result = $this->shopify->mutate('customerCreate', self::CUSTOMER_CREATE, ['input' => $input]);
        }

        return $result['customer']['id'] ?? null;
    }
}
