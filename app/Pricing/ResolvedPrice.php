<?php

namespace App\Pricing;

final readonly class ResolvedPrice
{
    /**
     * @param  'list'|'exempt'|'price_list'|'category'|'volume+category'|'contract'  $source
     */
    public function __construct(
        public int $cents,
        public string $source,
        public ?int $priceListId = null,
        public ?int $contractPriceId = null,
        public ?int $categoryRuleId = null,
    ) {}
}
