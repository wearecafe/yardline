<?php

namespace App\Billing;

use App\Audit\Audit;
use App\Http\Controllers\Time\EmployeeController;
use App\Models\Employee;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Time\TimeSettings;
use Illuminate\Support\Facades\DB;

/**
 * The paid add-ons a tenant switches on themselves: Rentals, and Time &
 * attendance. Free during the trial; once subscribed, switching one on or
 * off changes the subscription, prorated on the next invoice.
 */
final class AddOns
{
    public static function setRentals(Tenant $tenant, bool $on): void
    {
        if ($tenant->rentals_enabled === $on) {
            return;
        }

        $tenant->forceFill(['rentals_enabled' => $on])->save();

        CurrentTenant::run($tenant, fn () => Audit::record($tenant, 'updated', ['rentals_enabled' => [! $on, $on]], $on ? 'Rentals switched on' : 'Rentals switched off'));

        Billing::changed($tenant);
    }

    /**
     * Turns time and attendance on or off, keeping its other settings. The
     * first time it's turned on, everyone who signs in is set up as an
     * employee. Returns how many were added.
     */
    public static function setTime(Tenant $tenant, bool $on): int
    {
        $current = TimeSettings::for($tenant);

        if ($current->enabled === $on) {
            return 0;
        }

        $added = CurrentTenant::run($tenant, fn () => DB::transaction(function () use ($tenant, $current, $on) {
            $settings = $tenant->settings ?? [];
            $settings['time'] = [...$current->toArray(), 'enabled' => $on];
            $tenant->forceFill(['settings' => $settings])->save();

            Audit::record($tenant, 'updated', ['time_enabled' => [! $on, $on]], $on ? 'Time and attendance switched on' : 'Time and attendance switched off');

            return $on && ! Employee::query()->exists() ? EmployeeController::provision() : 0;
        }));

        Billing::changed($tenant);

        return $added;
    }
}
