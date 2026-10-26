<?php

namespace App\Tax;

/**
 * Where the sale happens for tax purposes: the yard for pickups, the
 * delivery address otherwise.
 */
final readonly class TaxAddress
{
    public function __construct(
        public ?string $line1,
        public ?string $line2,
        public ?string $city,
        public ?string $region,
        public ?string $postalCode,
        public string $country = 'CA',
    ) {}

    /**
     * @param  array<string, string|null>|null  $address  keys: line1, line2, city, region|state, postal_code, country
     */
    public static function fromArray(?array $address): self
    {
        $address ??= [];

        return new self(
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            $address['city'] ?? null,
            $address['region'] ?? $address['state'] ?? null,
            $address['postal_code'] ?? null,
            ($address['country'] ?? null) ?: 'CA',
        );
    }

    public function isComplete(): bool
    {
        return filled($this->postalCode) && filled($this->country);
    }
}
