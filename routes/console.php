<?php

use App\Models\DriverPing;
use App\Support\Demo;
use Illuminate\Support\Facades\Schedule;

// Rebuild the demo yard every night so it's tidy and "Today" always has work.
// Only runs when YARDLINE_DEMO=true; real yards are never touched.
Schedule::command('yardline:demo:reset --force')
    ->dailyAt('03:00')
    ->timezone('America/Toronto')
    ->when(fn () => Demo::enabled())
    ->withoutOverlapping();

// The demo driver's "phone": keeps Truck 2's position fresh.
Schedule::command('yardline:demo:drive')
    ->everyFiveMinutes()
    ->when(fn () => Demo::enabled())
    ->withoutOverlapping();

// Rental pickup, return and overdue reminders, by email and text.
Schedule::command('yardline:rentals:remind')->everyFifteenMinutes()->withoutOverlapping();

// QuickBooks: bring in payments entered there, and a nightly re-send and check.
Schedule::command('yardline:quickbooks:pull')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('yardline:quickbooks:check')->dailyAt('02:30')->timezone('America/Toronto')->withoutOverlapping();

// Card fees on payments, for the profit report.
Schedule::command('yardline:payments:fees')->hourly()->withoutOverlapping();

// Drivers' phone positions are kept for 30 days.
Schedule::command('model:prune', ['--model' => [DriverPing::class]])->dailyAt('02:15')->timezone('America/Toronto')->withoutOverlapping();
