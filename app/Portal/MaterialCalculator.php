<?php

namespace App\Portal;

use App\Enums\QuantityRounding;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Orders\QuantityFit;
use App\Pricing\PriceResolver;

/**
 * The patio calculator on the contractor portal: from an area and a base
 * depth, how many pallets of pavers, tonnes of base and bedding sand, and
 * bags of polymeric sand, at the contractor's own prices.
 */
class MaterialCalculator
{
    public const WASTE = 0.05;

    /** Tonnes per cubic yard, compacted. */
    public const BASE_DENSITY = 1.4;

    public const SAND_DENSITY = 1.35;

    /** Square feet one bag of polymeric sand covers. */
    public const POLY_COVERAGE = 75;

    public function __construct(private readonly PriceResolver $prices) {}

    /**
     * The products the calculator uses, found by name in the catalog.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function products(?Organization $organization): array
    {
        $find = fn (string ...$words) => Product::query()
            ->with('units')
            ->where('kind', 'sale')
            ->where('is_active', true)
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhereLike('name', "%{$word}%");
                }
            })
            ->orderBy('id')
            ->first();

        $pavers = $this->pavers();

        return [
            'pavers' => $this->describe($pavers, $organization, preferPallet: true),
            'base' => $this->describe($find('Granular', 'clear stone', 'base'), $organization),
            'bedding' => $this->describe($find('screenings', 'bedding sand', 'concrete sand'), $organization),
            'polymeric' => $this->describe($find('Polymeric'), $organization),
        ];
    }

    /**
     * The pavers the calculator prices: the first paver sold with pallets.
     */
    public function pavers(): ?Product
    {
        return Product::query()->with('units')->where('kind', 'sale')->where('is_active', true)->whereLike('name', '%paver%')->whereNotNull('per_pallet')->orderBy('id')->first();
    }

    /**
     * Pavers for an area as the product's rounding mix of units, with
     * waste, at the account's prices. Empty when the area can't be sold.
     *
     * @return list<array{product_id: int, unit_id: int, unit: string, quantity: float, price_cents: int}>
     */
    public function mix(Product $pavers, float $area, ?Organization $organization): array
    {
        $fit = QuantityFit::check($pavers, null, round($area * (1 + self::WASTE), 3));

        $lines = $fit->fits()
            ? [['unit' => $fit->unit, 'quantity' => $fit->quantity]]
            : $fit->lines;

        return array_map(fn (array $line) => [
            'product_id' => $pavers->id,
            'unit_id' => $line['unit']->id,
            'unit' => $line['unit']->name,
            'quantity' => $line['quantity'],
            'price_cents' => $this->prices->unitPrice($pavers, $organization, $line['quantity'], $line['unit'])->cents,
        ], $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describe(?Product $product, ?Organization $organization, bool $preferPallet = false): ?array
    {
        if ($product === null) {
            return null;
        }

        $sellable = $product->sellableUnits();
        $unit = $preferPallet
            ? ($sellable->first(fn (ProductUnit $u) => str_contains(strtolower($u->name), 'pallet')) ?? $product->defaultSellingUnit())
            : $product->defaultSellingUnit();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'unit_id' => $unit?->id,
            'unit' => $unit->name ?? $product->unit->label(),
            'factor' => (float) ($unit->factor ?? 1),
            'per_pallet' => $product->per_pallet !== null ? (float) $product->per_pallet : null,
            // Pavers that round are priced as a mix of units (pallets and
            // layers) from mix() as the area changes.
            'rounds' => $product->quantity_rounding !== QuantityRounding::Off,
            'price_cents' => $this->prices->unitPrice($product, $organization, 1, $unit)->cents,
        ];
    }
}
