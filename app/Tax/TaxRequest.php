<?php

namespace App\Tax;

final readonly class TaxRequest
{
    /**
     * @param  list<TaxLine>  $lines
     */
    public function __construct(
        public string $currency,
        public TaxAddress $address,
        public array $lines,
        public bool $exempt = false,
    ) {}
}
