<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case Picking = 'picking';
    case Picked = 'picked';       // every line pulled and staged
    case Loaded = 'loaded';       // delivery: on the truck
    case Ready = 'ready';         // pickup: waiting at the yard
    case Out = 'out';             // delivery: truck on its way
    case Delivered = 'delivered';
    case PickedUp = 'picked_up';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Picking => 'Picking',
            self::Picked => 'Picked',
            self::Loaded => 'Loaded',
            self::Ready => 'Ready for pickup',
            self::Out => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::PickedUp => 'Picked up',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isDone(): bool
    {
        return in_array($this, [self::Delivered, self::PickedUp, self::Cancelled], true);
    }
}
