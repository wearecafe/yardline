<?php

namespace App\Orders;

use App\Enums\QuantityRounding;
use App\Models\Product;
use App\Models\ProductUnit;
use LogicException;

/**
 * Whether a quantity can be sold as asked, and if not, what the product's
 * rounding setting offers instead: the nearest mix of its sellable units.
 * Staff see the offer and accept it before it goes on the order.
 */
final class QuantityFit
{
    /**
     * @param  list<array{unit: ProductUnit, quantity: float}>  $lines  the rounded mix, when there is one
     */
    private function __construct(
        public readonly Product $product,
        public readonly ProductUnit $unit,
        public readonly float $quantity,
        public readonly ?string $problem,
        public readonly array $lines = [],
        public readonly float $baseRequested = 0.0,
        public readonly float $baseTotal = 0.0,
    ) {}

    public static function check(Product $product, ?ProductUnit $unit, string|int|float $quantity): self
    {
        $product->loadMissing('units');
        $unit ??= $product->units->firstWhere('is_base', true);

        if ($unit === null) {
            OrderRules::fail("{$product->name} has no selling units.", 'product_unit_id');
        }

        $quantity = (float) $quantity;
        $problem = $unit->quantityProblem($quantity);

        if ($problem === null || $product->quantity_rounding === QuantityRounding::Off) {
            return new self($product, $unit, $quantity, $problem);
        }

        $target = (float) $unit->toBase($quantity);
        $sellable = $product->sellableUnits()->keyBy('id');
        $mix = UnitMix::solve(
            $target,
            $sellable->values()->map(fn (ProductUnit $u) => [
                'key' => $u->id,
                'factor' => $u->factor,
                'increment' => $u->increment,
                'min_quantity' => $u->min_quantity,
            ])->all(),
            $product->quantity_rounding === QuantityRounding::Up,
        );

        if ($mix === null) {
            $smallest = $sellable->sortBy(fn (ProductUnit $u) => (float) $u->factor * $u->smallestSale())->first();
            $problem = $smallest === null
                ? "{$product->name} has no units for sale."
                : self::number($target).' '.$product->unit->label().' is less than the smallest sale, '
                    .self::unitText($smallest->smallestSale(), $smallest->name).'.';

            return new self($product, $unit, $quantity, $problem, baseRequested: $target);
        }

        $lines = array_map(fn (array $line) => [
            'unit' => $sellable->get($line['key']) ?? throw new LogicException('Mixed in a unit that is not for sale.'),
            'quantity' => $line['quantity'],
        ], $mix['lines']);

        return new self($product, $unit, $quantity, $problem, $lines, $target, $mix['total']);
    }

    public function fits(): bool
    {
        return $this->problem === null;
    }

    public function canRound(): bool
    {
        return $this->lines !== [];
    }

    /**
     * "2 × Pallet (116.82 sq ft) + 7 × Layer (10.62 sq ft)"
     */
    public function summary(): string
    {
        return implode(' + ', array_map(
            fn (array $line) => self::unitText($line['quantity'], $line['unit']->name),
            $this->lines,
        ));
    }

    /**
     * What the screens show and send back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $base = $this->product->unit->label();

        return [
            'fits' => $this->fits(),
            'problem' => $this->problem,
            'proposal' => $this->canRound() ? [
                'direction' => $this->product->quantity_rounding->value,
                'summary' => $this->summary(),
                'requested' => self::number($this->baseRequested).' '.$base,
                'total' => self::number($this->baseTotal).' '.$base,
                'exact' => abs($this->baseTotal - $this->baseRequested) < 1e-6,
                'lines' => array_map(fn (array $line) => [
                    'product_unit_id' => $line['unit']->id,
                    'unit' => $line['unit']->name,
                    'quantity' => self::number($line['quantity']),
                ], $this->lines),
            ] : null,
        ];
    }

    private static function unitText(float $quantity, string $unit): string
    {
        return self::number($quantity).' × '.$unit;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
