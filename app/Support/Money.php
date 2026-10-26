<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer-cent helpers. Quantities are decimals with up to three places
 * (tonnes, cubic yards); money is always whole cents.
 */
final class Money
{
    /**
     * quantity × unit price, rounded half-up to the cent.
     */
    public static function extend(string|int|float $quantity, int $unitCents): int
    {
        $milli = self::toMilli($quantity);

        return intdiv($milli * $unitCents + 500, 1000);
    }

    /**
     * Parse a dollar amount typed by a person ("1,234.5") into cents.
     */
    public static function parse(string|int|float|null $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $clean = str_replace([',', '$', ' '], '', (string) $amount);

        if (! is_numeric($clean)) {
            throw new InvalidArgumentException("Not a money amount: {$amount}");
        }

        return (int) round(((float) $clean) * 100);
    }

    public static function toMilli(string|int|float $quantity): int
    {
        return (int) round(((float) $quantity) * 1000);
    }

    public static function format(int $cents): string
    {
        return sprintf('%s$%s', $cents < 0 ? '-' : '', number_format(abs($cents) / 100, 2));
    }
}
