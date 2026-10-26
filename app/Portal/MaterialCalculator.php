<?php

namespace App\Portal;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnit;
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

        $pavers = Product::query()->with('units')->where('kind', 'sale')->where('is_active', true)->whereLike('name', '%paver%')->whereNotNull('per_pallet')->orderBy('id')->first();

        return [
            'pavers' => $this->describe($pavers, $organization, preferPallet: true),
            'base' => $this->describe($find('Granular', 'clear stone', 'base'), $organization),
            'bedding' => $this->describe($find('screenings', 'bedding sand', 'concrete sand'), $organization),
            'polymeric' => $this->describe($find('Polymeric'), $organization),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describe(?Product $product, ?Organization $organization, bool $preferPallet = false): ?array
    {
        if ($product === null) {
            return null;
        }

        $unit = $preferPallet
            ? ($product->units->first(fn (ProductUnit $u) => str_contains(strtolower($u->name), 'pallet')) ?? $product->units->firstWhere('is_base', true))
            : $product->units->firstWhere('is_base', true);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'unit_id' => $unit?->id,
            'unit' => $unit?->name ?? $product->unit->label(),
            'factor' => (float) ($unit->factor ?? 1),
            'per_pallet' => $product->per_pallet !== null ? (float) $product->per_pallet : null,
            'price_cents' => $this->prices->unitPrice($product, $organization, 1, $unit)->cents,
        ];
    }
}
