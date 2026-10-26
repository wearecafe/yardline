<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Fulfilled = 'fulfilled';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Confirmed;
    }
}
