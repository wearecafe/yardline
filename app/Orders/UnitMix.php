<?php

namespace App\Orders;

/**
 * The nearest mix of selling units to a base quantity: 300 sq ft of pavers
 * sold by the pallet (116.82) and the layer (10.62) is 2 pallets + 7 layers
 * (307.98) rounding up, or 2 pallets + 6 layers (297.36) rounding down.
 *
 * Each unit is sold in whole steps (its increment, or 0.001 when it has
 * none) and at least its minimum. Rounding up finds the smallest total at
 * or above the target; rounding down the largest at or below it. Ties go
 * to the mix with the most in the bigger units, so 11 layers come out as
 * 1 pallet. Plain arithmetic on arrays, so it has no models to load.
 */
final class UnitMix
{
    private const EPSILON = 1e-6;

    /** Counts tried below the greedy count for each bigger unit. */
    private const SPAN = 60;

    /** Upper bound on the mixes tried, however many units there are. */
    private const BUDGET = 50_000;

    /** @var list<array{key: int|string, size: float, step: float, min_steps: int}> */
    private array $units = [];

    private float $target = 0.0;

    private bool $up = true;

    private int $tried = 0;

    /** @var list<int>|null */
    private ?array $best = null;

    private float $bestTotal = 0.0;

    /**
     * @param  array<int, array{key: int|string, factor: float|string, increment?: float|string|null, min_quantity?: float|string|null}>  $units  the sellable units
     * @return array{total: float, lines: list<array{key: int|string, quantity: float}>}|null null when no mix fits (rounding down below the smallest sale)
     */
    public static function solve(float $target, array $units, bool $up): ?array
    {
        return (new self)->run($target, $units, $up);
    }

    /**
     * @param  array<int, array{key: int|string, factor: float|string, increment?: float|string|null, min_quantity?: float|string|null}>  $units
     * @return array{total: float, lines: list<array{key: int|string, quantity: float}>}|null
     */
    private function run(float $target, array $units, bool $up): ?array
    {
        foreach ($units as $unit) {
            $factor = (float) $unit['factor'];
            $increment = (float) ($unit['increment'] ?? 0);
            $step = $increment > 0 ? $increment : 0.001;
            $min = (float) ($unit['min_quantity'] ?? 0);

            if ($factor <= 0) {
                continue;
            }

            $this->units[] = [
                'key' => $unit['key'],
                'size' => $factor * $step,
                'step' => $step,
                'min_steps' => max(1, (int) ceil($min / $step - self::EPSILON)),
            ];
        }

        if ($this->units === [] || $target <= self::EPSILON) {
            return null;
        }

        usort($this->units, fn (array $a, array $b) => $b['size'] <=> $a['size']);

        $this->target = $target;
        $this->up = $up;
        $this->search(0, $target, []);

        if ($this->best === null) {
            return null;
        }

        $lines = [];

        foreach ($this->best as $i => $count) {
            if ($count > 0) {
                $lines[] = [
                    'key' => $this->units[$i]['key'],
                    'quantity' => round($count * $this->units[$i]['step'], 3),
                ];
            }
        }

        return ['total' => round($this->bestTotal, 6), 'lines' => $lines];
    }

    /**
     * @param  list<int>  $counts  steps chosen for the bigger units so far
     */
    private function search(int $i, float $remaining, array $counts): void
    {
        if ($this->tried >= self::BUDGET) {
            return;
        }

        $unit = $this->units[$i];
        $last = $i === count($this->units) - 1;

        if ($last) {
            $steps = $this->up
                ? (int) max(0, ceil(($remaining - self::EPSILON) / $unit['size']))
                : (int) max(0, floor(($remaining + self::EPSILON) / $unit['size']));

            if ($steps > 0 && $steps < $unit['min_steps']) {
                $steps = $this->up ? $unit['min_steps'] : 0;
            }

            $this->consider([...$counts, $steps]);

            return;
        }

        $smallest = $this->units[count($this->units) - 1]['size'];
        $greedy = (int) max(0, floor(($remaining + self::EPSILON) / $unit['size']));
        $high = $this->up && $remaining > self::EPSILON ? $greedy + 1 : $greedy;
        $low = max(0, $greedy - min(self::SPAN, (int) ceil($unit['size'] / $smallest) + 1));

        for ($steps = $high; $steps >= $low; $steps--) {
            if ($steps > 0 && $steps < $unit['min_steps']) {
                continue;
            }

            $this->search($i + 1, $remaining - $steps * $unit['size'], [...$counts, $steps]);
        }
    }

    /**
     * @param  list<int>  $counts
     */
    private function consider(array $counts): void
    {
        $this->tried++;
        $total = 0.0;

        foreach ($counts as $i => $steps) {
            $total += $steps * $this->units[$i]['size'];
        }

        $fits = $this->up
            ? $total >= $this->target - self::EPSILON
            : $total <= $this->target + self::EPSILON && $total > self::EPSILON;

        if (! $fits) {
            return;
        }

        if ($this->best === null || $this->better($total, $counts)) {
            $this->best = $counts;
            $this->bestTotal = $total;
        }
    }

    /**
     * @param  list<int>  $counts
     */
    private function better(float $total, array $counts): bool
    {
        if (abs($total - $this->bestTotal) > self::EPSILON) {
            return $this->up ? $total < $this->bestTotal : $total > $this->bestTotal;
        }

        // Same total: as much as possible in the bigger units, so full
        // pallets sell at the pallet price.
        foreach ($counts as $i => $steps) {
            if ($steps !== $this->best[$i]) {
                return $steps > $this->best[$i];
            }
        }

        return false;
    }
}
