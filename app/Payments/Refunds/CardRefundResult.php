<?php

namespace App\Payments\Refunds;

/**
 * What the processor said about a refund it accepted.
 */
final readonly class CardRefundResult
{
    /**
     * @param  string  $status  succeeded | pending | failed | canceled | requires_action
     */
    public function __construct(
        public string $id,
        public string $status,
    ) {}

    public function failed(): bool
    {
        return in_array($this->status, ['failed', 'canceled'], true);
    }
}
