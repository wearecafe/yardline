<?php

namespace App\Time;

use App\Models\PayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Pay periods, made as they're needed from the business's frequency:
 * weekly or every two weeks from an anchor date, or the 1st–15th and
 * 16th–end of each month.
 */
class PayPeriods
{
    public function __construct(private TimeSettings $settings) {}

    public static function make(): self
    {
        return new self(TimeSettings::current());
    }

    /**
     * The period a date falls in. A period already on file wins, so
     * changing the frequency never reshapes a period people have worked.
     */
    public function containing(CarbonImmutable $date): PayPeriod
    {
        $day = $date->toDateString();

        $existing = PayPeriod::query()
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        [$start, $end] = $this->bounds($date);

        // A period on file that overlaps (after a frequency change) cuts this one short.
        $after = PayPeriod::query()->whereDate('starts_on', '>', $day)->whereDate('starts_on', '<=', $end->toDateString())->min('starts_on');
        $before = PayPeriod::query()->whereDate('ends_on', '<', $day)->whereDate('ends_on', '>=', $start->toDateString())->max('ends_on');

        if (is_string($after)) {
            $end = CarbonImmutable::parse($after)->subDay();
        }

        if (is_string($before)) {
            $start = CarbonImmutable::parse($before)->addDay();
        }

        try {
            return PayPeriod::query()->create(['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()]);
        } catch (QueryException) {
            // Made at the same moment by another request.
            return PayPeriod::query()->whereDate('starts_on', $start->toDateString())->firstOrFail();
        }
    }

    public function current(string $timezone): PayPeriod
    {
        return $this->containing(CarbonImmutable::now($timezone));
    }

    public function previous(PayPeriod $period): PayPeriod
    {
        return $this->containing($period->starts_on->subDay());
    }

    public function next(PayPeriod $period): PayPeriod
    {
        return $this->containing($period->ends_on->addDay());
    }

    /**
     * Start and end (inclusive) of the period a date falls in.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(CarbonImmutable $date): array
    {
        $date = $date->startOfDay();

        if ($this->settings->frequency === 'semimonthly') {
            return $date->day <= 15
                ? [$date->startOfMonth(), $date->startOfMonth()->addDays(14)]
                : [$date->startOfMonth()->addDays(15), $date->endOfMonth()->startOfDay()];
        }

        $length = $this->settings->frequency === 'weekly' ? 7 : 14;
        $anchor = CarbonImmutable::parse($this->settings->anchor, $date->getTimezone())->startOfDay();
        $offset = (int) floor($anchor->diffInDays($date, false));
        $index = intdiv($offset - (($offset % $length + $length) % $length), $length);
        $start = $anchor->addDays($index * $length);

        return [$start, $start->addDays($length - 1)];
    }
}
