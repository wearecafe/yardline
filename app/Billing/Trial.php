<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * New businesses get a free trial (yardline.billing.trial_days). The date
 * stored is the trial's last day, so a 14-day trial started today has
 * today as day 1 and ends on day 14.
 */
final class Trial
{
    public static function endsOn(string $timezone = 'America/Toronto'): string
    {
        return CarbonImmutable::now($timezone)->addDays(max(1, (int) config('yardline.billing.trial_days', 14)) - 1)->toDateString();
    }
}
