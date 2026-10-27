<?php

namespace Tests\Unit;

use App\Orders\UnitMix;
use PHPUnit\Framework\TestCase;

/**
 * The nearest mix of selling units: interlock sold by the pallet (116.82
 * sq ft) and the layer (10.62 sq ft), never by the single paver.
 */
class UnitMixTest extends TestCase
{
    /** @var list<array{key: string, factor: float, increment: int}> */
    private const INTERLOCK = [
        ['key' => 'layer', 'factor' => 10.62, 'increment' => 1],
        ['key' => 'pallet', 'factor' => 116.82, 'increment' => 1],
    ];

    public function test_rounds_up_to_whole_pallets_and_layers(): void
    {
        $mix = UnitMix::solve(300, self::INTERLOCK, up: true);

        $this->assertNotNull($mix);
        $this->assertEqualsWithDelta(307.98, $mix['total'], 1e-6);
        $this->assertSame([['key' => 'pallet', 'quantity' => 2.0], ['key' => 'layer', 'quantity' => 7.0]], $mix['lines']);
    }

    public function test_rounds_down_to_whole_pallets_and_layers(): void
    {
        $mix = UnitMix::solve(300, self::INTERLOCK, up: false);

        $this->assertNotNull($mix);
        $this->assertEqualsWithDelta(297.36, $mix['total'], 1e-6);
        $this->assertSame([['key' => 'pallet', 'quantity' => 2.0], ['key' => 'layer', 'quantity' => 6.0]], $mix['lines']);
    }

    public function test_eleven_layers_are_a_pallet(): void
    {
        $mix = UnitMix::solve(116.82, self::INTERLOCK, up: true);

        $this->assertSame([['key' => 'pallet', 'quantity' => 1.0]], $mix['lines'] ?? null);
    }

    public function test_rounding_down_below_the_smallest_sale_finds_nothing(): void
    {
        $this->assertNull(UnitMix::solve(5, self::INTERLOCK, up: false));
        $this->assertSame([['key' => 'layer', 'quantity' => 1.0]], UnitMix::solve(5, self::INTERLOCK, up: true)['lines'] ?? null);
    }

    public function test_finds_the_closest_total_when_units_do_not_divide_evenly(): void
    {
        // 1 pallet + 1 layer is 125; 12 layers is closer at 120.
        $mix = UnitMix::solve(118, [
            ['key' => 'pallet', 'factor' => 115, 'increment' => 1],
            ['key' => 'layer', 'factor' => 10, 'increment' => 1],
        ], up: true);

        $this->assertSame(120.0, $mix['total'] ?? null);
        $this->assertSame([['key' => 'layer', 'quantity' => 12.0]], $mix['lines'] ?? null);
    }

    public function test_respects_steps_and_minimums(): void
    {
        // Pallets only from 2; half-yard steps on cubic yards.
        $mix = UnitMix::solve(130, [
            ['key' => 'pallet', 'factor' => 116.82, 'increment' => 1, 'min_quantity' => 2],
            ['key' => 'layer', 'factor' => 10.62, 'increment' => 1],
        ], up: true);
        $this->assertSame([['key' => 'layer', 'quantity' => 13.0]], $mix['lines'] ?? null);

        $mix = UnitMix::solve(2.1, [['key' => 'yard', 'factor' => 1.35, 'increment' => 0.5]], up: true);
        $this->assertSame([['key' => 'yard', 'quantity' => 2.0]], $mix['lines'] ?? null);
        $this->assertEqualsWithDelta(2.7, $mix['total'] ?? 0, 1e-6);
    }
}
