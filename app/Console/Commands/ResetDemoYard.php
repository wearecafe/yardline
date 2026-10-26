<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes and re-creates ONLY the fictional demo yard. Every other yard is
 * untouched.
 */
class ResetDemoYard extends Command
{
    protected $signature = 'yardline:demo:reset {--force : Run without confirmation (for the scheduler)}';

    protected $description = 'Rebuild the Escarpment Stone demo yard with fresh data dated today';

    /**
     * Tenant-owned tables, children before parents, so deletes work on any
     * database without relying on cascade order.
     *
     * @var list<string>
     */
    private const TABLES = [
        'rental_payments',
        'rental_notifications',
        'rental_charges',
        'inspection_photos',
        'rental_inspections',
        'rental_agreements',
        'rental_items',
        'rentals',
        'maintenance_records',
        'rental_units',
        'customer_documents',
        'delivery_zones',
        'payments',
        'order_lines',
        'stock_movements',
        'orders',
        'stock_levels',
        'price_list_items',
        'external_references',
        'integrations',
        'customers',
        'organizations',
        'price_lists',
        'products',
        'locations',
        'sequences',
    ];

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', DemoYardSeeder::SLUG)->first();

        if ($tenant !== null && ! $this->option('force') && ! $this->confirm("Delete and rebuild {$tenant->name}? Other yards are not affected.", true)) {
            return self::FAILURE;
        }

        if ($tenant !== null) {
            DB::transaction(function () use ($tenant) {
                foreach (self::TABLES as $table) {
                    DB::table($table)->where('tenant_id', $tenant->id)->delete();
                }

                $userIds = DB::table('users')->where('tenant_id', $tenant->id)->pluck('id');
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();

                $tenant->delete();
            });

            $this->line("Removed {$tenant->name}.");
        }

        CurrentTenant::set(null);
        $this->callSilent('db:seed', ['--class' => DemoYardSeeder::class, '--force' => true]);
        CurrentTenant::set(null);

        $this->info('Demo yard rebuilt.');

        return self::SUCCESS;
    }
}
