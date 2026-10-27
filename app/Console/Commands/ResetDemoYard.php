<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
        'user_activity_days',
        'webhook_deliveries',
        'audit_events',
        'payroll_exports',
        'timesheets',
        'pay_periods',
        'time_punches',
        'time_adjustments',
        'employees',
        'cycle_count_lines',
        'cycle_counts',
        'stock_transfer_lines',
        'stock_transfers',
        'purchase_order_lines',
        'purchase_orders',
        'pallet_movements',
        'credit_note_lines',
        'credit_notes',
        'invoices',
        'stops',
        'driver_pings',
        'truck_assignments',
        'driver_truck',
        'shipment_lines',
        'shipments',
        'trucks',
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
        'order_revisions',
        'payments',
        'order_lines',
        'stock_movements',
        'orders',
        'organization_locations',
        'contract_prices',
        'customer_organization',
        'stock_levels',
        'stock_lots',
        'price_list_items',
        'product_units',
        'integrations',
        'customers',
        'organizations',
        'price_lists',
        'products',
        'suppliers',
        'locations',
        'sequences',
    ];

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', DemoYardSeeder::SLUG)->first();

        if ($tenant !== null && ! $this->option('force') && ! $this->confirm("Delete and rebuild {$tenant->name}? Other yards are not affected.", true)) {
            return self::FAILURE;
        }

        // The same lock /demo takes when it builds a missing demo yard, so a
        // visitor arriving mid-reset waits instead of building a second one.
        Cache::lock('yardline:demo:seed', 600)->block(120, function () use ($tenant) {
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
        });

        $this->info('Demo yard rebuilt.');

        return self::SUCCESS;
    }
}
