<?php

namespace App\Rentals;

use App\Enums\RateBasis;

/**
 * The cheapest way to charge one unit for a rental period.
 */
final readonly class RentalQuote
{
    /**
     * @param  array<string, int>  $periods  rate basis => count, e.g. ['week' => 1, 'day' => 2]
     */
    public function __construct(
        public int $cents,
        public array $periods,
        public int $billableDays,
        public int $dayRateEquivalentCents,
        public bool $freeSundayApplied,
        public bool $minimumApplied,
        public bool $weekendRateApplied,
    ) {}

    /**
     * "1 week", "1 week + 2 days", "3 days", "4 hours".
     */
    public function summary(): string
    {
        if ($this->weekendRateApplied) {
            return 'Weekend rate';
        }

        $parts = [];
        foreach ($this->periods as $basis => $count) {
            $parts[] = RateBasis::from($basis)->label($count);
        }

        return $parts === [] ? 'Minimum charge' : implode(' + ', $parts);
    }

    /**
     * Whether a longer-period rate beat paying day by day.
     */
    public function beatsDailyRate(): bool
    {
        return $this->dayRateEquivalentCents > $this->cents;
    }
}
