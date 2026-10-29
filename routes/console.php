<?php

use App\Models\DriverPing;
use App\Models\WebhookDelivery;
use App\Support\Demo;
use Illuminate\Support\Facades\Schedule;

// Rebuild the demo yard every night so it's tidy and "Today" always has work.
// Only runs when YARDRUNNER_DEMO=true; real yards are never touched.
Schedule::command('yardrunner:demo:reset --force')
    ->dailyAt('03:00')
    ->timezone('America/Toronto')
    ->when(fn () => Demo::enabled())
    ->withoutOverlapping();

// The demo driver's "phone": keeps Truck 2's position fresh.
Schedule::command('yardrunner:demo:drive')
    ->everyFiveMinutes()
    ->when(fn () => Demo::enabled())
    ->withoutOverlapping();

// Rental pickup, return and overdue reminders, by email and text.
Schedule::command('yardrunner:rentals:remind')->everyFifteenMinutes()->withoutOverlapping();

// QuickBooks: bring in payments entered there, and a nightly re-send and check.
Schedule::command('yardrunner:quickbooks:pull')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('yardrunner:quickbooks:check')->dailyAt('02:30')->timezone('America/Toronto')->withoutOverlapping();

// Yards' own sending domains: look for their DNS records.
Schedule::command('yardrunner:email:domains')->everyTenMinutes()->withoutOverlapping();

// Card fees on payments, for the profit report.
Schedule::command('yardrunner:payments:fees')->hourly()->withoutOverlapping();

// Drivers' phone positions and the webhook log are kept for 30 days.
Schedule::command('model:prune', ['--model' => [DriverPing::class, WebhookDelivery::class]])->dailyAt('02:15')->timezone('America/Toronto')->withoutOverlapping();

// Tenants whose trial ended overnight show as "Trial ended" in the back office.
Schedule::command('yardrunner:billing:refresh')->dailyAt('00:10')->timezone('America/Toronto')->withoutOverlapping();
