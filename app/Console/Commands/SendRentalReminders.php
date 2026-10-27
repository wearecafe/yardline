<?php

namespace App\Console\Commands;

use App\Alerts\StaffAlerts;
use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Models\Tenant;
use App\Rentals\Notifications\RentalNotifier;
use App\Rentals\RentalSettings;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Pickup reminders (the day before), return reminders and overdue notices,
 * for every yard. Safe to run often: each notice goes once (overdue: once a
 * day).
 */
class SendRentalReminders extends Command
{
    protected $signature = 'yardline:rentals:remind';

    protected $description = 'Send rental pickup, return and overdue reminders';

    public function handle(RentalNotifier $notifier, StaffAlerts $alerts): int
    {
        $sent = 0;

        // Only businesses with the Rentals add-on on.
        foreach (Tenant::query()->where('rentals_enabled', true)->cursor() as $tenant) {
            $sent += CurrentTenant::run($tenant, function () use ($tenant, $notifier, $alerts) {
                $settings = RentalSettings::for($tenant);
                $count = 0;

                $pickups = Rental::query()
                    ->where('status', RentalStatus::Reserved->value)
                    ->whereBetween('starts_at', [now(), now()->addDay()])
                    ->get();

                foreach ($pickups as $rental) {
                    $count += (int) $notifier->send($rental, 'pickup_reminder');
                }

                $returns = Rental::query()
                    ->where('status', RentalStatus::Out->value)
                    ->where('text_reminders', true)
                    ->whereBetween('ends_at', [now(), now()->addHours($settings->reminderHoursBeforeReturn)])
                    ->get();

                foreach ($returns as $rental) {
                    $count += (int) $notifier->send($rental, 'return_reminder');
                }

                $overdue = Rental::query()
                    ->where('status', RentalStatus::Out->value)
                    ->where('ends_at', '<', now()->subMinutes($settings->lateGraceMinutes))
                    ->get();

                foreach ($overdue as $rental) {
                    $count += (int) $notifier->send($rental, 'overdue');
                    // And the rental desk at its yard, on their phones.
                    $alerts->rentalOverdue($rental);
                }

                return $count;
            });
        }

        $this->info("Sent {$sent} rental reminders.");

        return self::SUCCESS;
    }
}
