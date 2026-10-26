<?php

namespace App\Enums;

/**
 * Selling units common in masonry and landscape supply.
 */
enum Unit: string
{
    case Each = 'each';
    case Pallet = 'pallet';
    case Cube = 'cube';
    case Bag = 'bag';
    case Bundle = 'bundle';
    case Tonne = 'tonne';
    case CubicYard = 'cubic_yard';
    case SquareFoot = 'sq_ft';
    case LinearFoot = 'lin_ft';

    public function label(): string
    {
        return match ($this) {
            self::Each => 'each',
            self::Pallet => 'pallet',
            self::Cube => 'cube',
            self::Bag => 'bag',
            self::Bundle => 'bundle',
            self::Tonne => 'tonne',
            self::CubicYard => 'cu yd',
            self::SquareFoot => 'sq ft',
            self::LinearFoot => 'lin ft',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $unit) => ['value' => $unit->value, 'label' => $unit->label()],
            self::cases(),
        );
    }
}
