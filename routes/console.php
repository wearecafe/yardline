<?php

use App\Support\Demo;
use Illuminate\Support\Facades\Schedule;

// Rebuild the demo yard every night so it's tidy and "Today" always has work.
// Only runs when YARDLINE_DEMO=true; real yards are never touched.
Schedule::command('yardline:demo:reset --force')
    ->dailyAt('03:00')
    ->timezone('America/Toronto')
    ->when(fn () => Demo::enabled())
    ->withoutOverlapping();
