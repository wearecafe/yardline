<?php

namespace App\Pricing;

final readonly class ResolvedPrice
{
    /**
     * @param  'list'|'price_list'|'contract'  $source
     */
    public function __construct(
        public int $cents,
        public string $source,
        public ?int $priceListId = null,
        public ?int $contractPriceId = null,
    ) {}
}
