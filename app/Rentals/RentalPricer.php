<?php

namespace App\Rentals;

use App\Enums\RateBasis;
use App\Models\Product;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Prices a rental at the cheapest combination of the product's rates.
 *
 * - Time out is rounded up to whole hours; a 30-minute grace keeps a
 *   9:00 to 9:20-next-day return to one day.
 * - Rates are hour, half day (4 h), day (24 h), week and month (4 weeks).
 *   Any combination that covers the time can be used, so a week replaces
 *   seven days whenever it's cheaper.
 * - Sunday is free when the rental starts on a Saturday and runs through
 *   Sunday (when the yard turns that on).
 * - A weekend rate, if set, covers Friday or Saturday pickup with return by
 *   Monday noon.
 * - Never less than the product's minimum charge.
 */
class RentalPricer
{
    private const GRACE_MINUTES = 30;

    public function __construct(private readonly RentalSettings $settings) {}

    public static function forCurrentTenant(): self
    {
        return new self(RentalSettings::current());
    }

    public function quote(Product $product, DateTimeInterface $start, DateTimeInterface $end): RentalQuote
    {
        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);

        $minutes = max(0, (int) $start->diffInMinutes($end, false) - self::GRACE_MINUTES);
        $hours = max(1, (int) ceil($minutes / 60));

        $freeSunday = $this->settings->freeSundayWithSaturday
            && $start->isSaturday()
            && $hours > 24;

        if ($freeSunday) {
            $hours = max(1, $hours - 24);
        }

        $rates = $this->rates($product);
        [$cents, $periods] = $this->cheapest($rates, $hours);

        $billableDays = max(1, (int) ceil($hours / 24));
        $dayEquivalent = $billableDays * $product->price_cents;

        $weekendApplied = false;
        if ($product->rate_weekend_cents !== null && $this->isWeekend($start, $end) && $product->rate_weekend_cents < $cents) {
            $cents = $product->rate_weekend_cents;
            $periods = [];
            $weekendApplied = true;
        }

        $minimumApplied = false;
        if ($cents < $product->minimum_charge_cents) {
            $cents = $product->minimum_charge_cents;
            $minimumApplied = true;
        }

        return new RentalQuote(
            cents: $cents,
            periods: $periods,
            billableDays: $billableDays,
            dayRateEquivalentCents: $dayEquivalent,
            freeSundayApplied: $freeSunday,
            minimumApplied: $minimumApplied,
            weekendRateApplied: $weekendApplied,
        );
    }

    /**
     * Rate basis => [hours, cents], for the rates this product has.
     *
     * @return array<string, array{int, int}>
     */
    private function rates(Product $product): array
    {
        $rates = [
            RateBasis::Hour->value => $product->rate_hour_cents,
            RateBasis::HalfDay->value => $product->rate_half_day_cents,
            RateBasis::Day->value => $product->price_cents,
            RateBasis::Week->value => $product->rate_week_cents,
            RateBasis::Month->value => $product->rate_month_cents,
        ];

        $out = [];
        foreach ($rates as $basis => $cents) {
            if ($cents !== null && $cents > 0) {
                $out[$basis] = [RateBasis::from($basis)->hours(), $cents];
            }
        }

        // A product always has at least a day rate.
        return $out ?: [RateBasis::Day->value => [24, max(0, $product->price_cents)]];
    }

    /**
     * Unbounded "cover at least N hours at the lowest cost". Small N, so a
     * straight dynamic program is fine: at most a few thousand hours.
     *
     * @param  array<string, array{int, int}>  $rates
     * @return array{int, array<string, int>}
     */
    private function cheapest(array $rates, int $hours): array
    {
        $cost = array_fill(0, $hours + 1, PHP_INT_MAX);
        $choice = array_fill(0, $hours + 1, null);
        $cost[0] = 0;

        for ($h = 1; $h <= $hours; $h++) {
            foreach ($rates as $basis => [$size, $price]) {
                $previous = max(0, $h - $size);

                if ($cost[$previous] === PHP_INT_MAX) {
                    continue;
                }

                $candidate = $cost[$previous] + $price;

                if ($candidate < $cost[$h]) {
                    $cost[$h] = $candidate;
                    $choice[$h] = $basis;
                }
            }
        }

        $periods = [];
        for ($h = $hours; $h > 0;) {
            $basis = $choice[$h];

            if ($basis === null) {
                break;
            }

            $periods[$basis] = ($periods[$basis] ?? 0) + 1;
            $h = max(0, $h - $rates[$basis][0]);
        }

        // Largest periods first: "1 week + 2 days", not "2 days + 1 week".
        $order = array_flip(array_map(fn (RateBasis $basis) => $basis->value, array_reverse(RateBasis::cases())));
        uksort($periods, fn (string $a, string $b) => $order[$a] <=> $order[$b]);

        return [$cost[$hours] === PHP_INT_MAX ? 0 : $cost[$hours], $periods];
    }

    private function isWeekend(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (! $start->isFriday() && ! $start->isSaturday()) {
            return false;
        }

        $mondayNoon = $start->next(CarbonImmutable::MONDAY)->setTime(12, 0);

        return $end->lessThanOrEqualTo($mondayNoon);
    }
}
