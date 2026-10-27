<?php

namespace App\Http\Controllers\Time\Concerns;

use App\Models\PayPeriod;
use App\Tenancy\CurrentTenant;
use App\Time\PayPeriods;
use App\Time\TimePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Shared by the time and attendance screens: the yard's time zone, the
 * pay period being looked at, and links to the ones either side.
 */
trait UsesTime
{
    protected function tz(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }

    protected function presenter(): TimePresenter
    {
        return new TimePresenter($this->tz());
    }

    /**
     * The period in ?period=, or the one today falls in.
     */
    protected function period(Request $request): PayPeriod
    {
        $periods = PayPeriods::make();

        if ($request->filled('period')) {
            $period = PayPeriod::query()->find($request->integer('period'));

            if ($period !== null) {
                return $period;
            }
        }

        if ($request->filled('date')) {
            try {
                return $periods->containing(CarbonImmutable::parse($request->string('date')->toString(), $this->tz()));
            } catch (\Throwable) {
                // Fall through to today.
            }
        }

        return $periods->current($this->tz());
    }

    /**
     * @return array<string, mixed>
     */
    protected function periodProps(PayPeriod $period): array
    {
        $today = CarbonImmutable::now($this->tz());

        return [
            'id' => $period->id,
            'label' => $period->label(),
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'status' => $period->status->value,
            'status_label' => $period->status->label(),
            'is_current' => $period->contains($today),
            'has_ended' => $period->ends_on->toDateString() < $today->toDateString(),
            // Dates either side, so moving between periods never makes one
            // far in the future by accident.
            'prev_date' => $period->starts_on->subDay()->toDateString(),
            'next_date' => $period->ends_on->addDay()->toDateString() <= $today->addDays(14)->toDateString() ? $period->ends_on->addDay()->toDateString() : null,
        ];
    }
}
