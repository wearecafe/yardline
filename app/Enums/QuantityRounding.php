<?php

namespace App\Enums;

/**
 * What a product does with a quantity that doesn't fit its sellable units:
 * refuse it, or offer the nearest mix of sellable units above or below.
 */
enum QuantityRounding: string
{
    case Off = 'off';
    case Up = 'up';
    case Down = 'down';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Don’t round',
            self::Up => 'Round up',
            self::Down => 'Round down',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $rounding) => ['value' => $rounding->value, 'label' => $rounding->label()],
            self::cases(),
        );
    }
}
