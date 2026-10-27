<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * What the profit report covers: a period of whole days in the business's
 * time zone, what to compare it with, and which yard, business and sales
 * channel. A sales channel applies to goods only, so choosing one leaves
 * rentals out.
 */
final readonly class ProfitFilters
{
    public const PERIODS = [
        'month' => 'This month',
        'last_month' => 'Last month',
        'quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'ytd' => 'Year to date',
        'last_12' => 'Last 12 months',
        'custom' => 'Custom dates',
    ];

    public const COMPARE = [
        'previous' => 'Previous period',
        'last_year' => 'Same period last year',
        'none' => 'No comparison',
    ];

    public const BUSINESS = [
        'all' => 'Goods and rentals',
        'goods' => 'Goods only',
        'rentals' => 'Rentals only',
    ];

    /** orders.source => label */
    public const CHANNELS = [
        'counter' => 'Counter',
        'yardline' => 'Staff-entered',
        'portal' => 'Contractor portal',
    ];

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public string $period = 'month',
        public string $compare = 'previous',
        public ?int $locationId = null,
        public string $business = 'all',
        public ?string $channel = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, string $timezone, ?CarbonImmutable $today = null): self
    {
        $data = Validator::make($input, [
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare' => ['nullable', Rule::in(array_keys(self::COMPARE))],
            'location_id' => ['nullable', 'integer'],
            'business' => ['nullable', Rule::in(array_keys(self::BUSINESS))],
            'channel' => ['nullable', Rule::in(array_keys(self::CHANNELS))],
        ])->validate();

        $today ??= CarbonImmutable::today($timezone);
        $period = (string) ($data['period'] ?? 'month');

        [$from, $to] = match ($period) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$today->startOfQuarter(), $today->endOfQuarter()],
            'last_quarter' => [$today->subQuarterNoOverflow()->startOfQuarter(), $today->subQuarterNoOverflow()->endOfQuarter()],
            'ytd' => [$today->startOfYear(), $today],
            'last_12' => [$today->subMonthsNoOverflow(11)->startOfMonth(), $today->endOfMonth()],
            'custom' => [
                CarbonImmutable::parse((string) $data['from'], $timezone),
                CarbonImmutable::parse((string) $data['to'], $timezone),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };

        return new self(
            from: $from->startOfDay(),
            to: $to->startOfDay(),
            timezone: $timezone,
            period: $period,
            compare: (string) ($data['compare'] ?? 'previous'),
            locationId: isset($data['location_id']) ? (int) $data['location_id'] : null,
            business: (string) ($data['business'] ?? 'all'),
            channel: isset($data['channel']) ? (string) $data['channel'] : null,
        );
    }

    /**
     * The same filters over other dates.
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from->startOfDay(), $to->startOfDay(), $this->timezone, 'custom', 'none', $this->locationId, $this->business, $this->channel);
    }

    /**
     * The period to compare with, if any. Whole months compare with the
     * same number of whole months before; other ranges with the same
     * number of days.
     */
    public function comparison(): ?self
    {
        if ($this->compare === 'last_year') {
            return $this->between($this->from->subYearNoOverflow(), $this->to->subYearNoOverflow());
        }

        if ($this->compare !== 'previous') {
            return null;
        }

        if ($this->isWholeMonths()) {
            $months = $this->months();
            $from = $this->from->subMonthsNoOverflow($months);

            return $this->between($from, $from->addMonthsNoOverflow($months)->subDay());
        }

        $days = $this->days();

        return $this->between($this->from->subDays($days), $this->from->subDay());
    }

    public function includesGoods(): bool
    {
        return $this->business !== 'rentals';
    }

    /** Rentals have no sales channel, so a channel filter leaves them out. */
    public function includesRentals(): bool
    {
        return $this->business !== 'goods' && $this->channel === null;
    }

    /** First instant of the period, in UTC, for timestamps. */
    public function startsAt(): CarbonImmutable
    {
        return $this->from->setTimezone($this->timezone)->startOfDay()->utc();
    }

    /** First instant after the period, in UTC, for timestamps. */
    public function endsBefore(): CarbonImmutable
    {
        return $this->to->setTimezone($this->timezone)->addDay()->startOfDay()->utc();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function label(): string
    {
        if ($this->isWholeMonths() && $this->months() === 1) {
            return $this->from->format('F Y');
        }

        if ($this->isWholeMonths()) {
            return $this->from->format('M Y').' – '.$this->to->format('M Y');
        }

        return $this->from->year === $this->to->year
            ? $this->from->format('M j').' – '.$this->to->format('M j, Y')
            : $this->from->format('M j, Y').' – '.$this->to->format('M j, Y');
    }

    /**
     * The query string that brings these filters back.
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'period' => $this->period,
            'from' => $this->period === 'custom' ? $this->fromDate() : null,
            'to' => $this->period === 'custom' ? $this->toDate() : null,
            'compare' => $this->compare,
            'location_id' => $this->locationId,
            'business' => $this->business,
            'channel' => $this->channel,
        ], fn ($value) => $value !== null);
    }

    private function isWholeMonths(): bool
    {
        return $this->from->day === 1 && $this->to->isSameDay($this->to->endOfMonth());
    }

    private function months(): int
    {
        return ($this->to->year - $this->from->year) * 12 + $this->to->month - $this->from->month + 1;
    }
}
