<?php

namespace App\Console\Commands;

use App\Fulfillment\DriverRun;
use App\Models\User;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Console\Command;

/**
 * Stands in for the demo driver's phone: moves Truck 2 back and forth
 * along its route so the dispatch board and the contractor portal always
 * have a fresh position to show. Only ever touches the demo yard.
 */
class DriveDemoTruck extends Command
{
    protected $signature = 'yardrunner:demo:drive';

    /** The name it had before the rename, kept so old habits and docs still work. */
    protected $aliases = ['yardline:demo:drive'];

    protected $description = 'Move the demo yard truck along its route, as if the driver phone were sharing its location';

    public function handle(DriverRun $run): int
    {
        $tenant = Demo::enabled() ? Demo::tenant() : null;

        if ($tenant === null) {
            return self::SUCCESS;
        }

        $driver = User::query()->where('tenant_id', $tenant->id)->where('email', DemoYardSeeder::DEMO_DRIVER)->first();

        if ($driver === null) {
            return self::SUCCESS;
        }

        // There and back, one point every five minutes.
        $route = DemoYardSeeder::DEMO_ROUTE;
        $legs = count($route) * 2 - 2;
        $step = intdiv((int) now()->timestamp, 300) % $legs;
        [$lat, $lng] = $route[$step < count($route) ? $step : $legs - $step];

        CurrentTenant::run($tenant, fn () => $run->locate($driver, $lat, $lng, 10, 35.0));

        return self::SUCCESS;
    }
}
