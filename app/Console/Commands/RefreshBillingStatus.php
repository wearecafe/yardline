<?php

namespace App\Console\Commands;

use App\Billing\Billing;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Access is worked out live, so a trial ends on time without this. It keeps
 * the status the back office lists and counts (Trial → Trial ended) up to
 * date for tenants nobody has opened since.
 */
class RefreshBillingStatus extends Command
{
    protected $signature = 'yardline:billing:refresh';

    protected $description = 'Bring every tenant’s plan, price and billing status up to date';

    public function handle(): int
    {
        $changed = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use (&$changed) {
            $before = $tenant->status;
            Billing::refresh($tenant);
            $changed += $tenant->status !== $before ? 1 : 0;
        });

        $this->info("{$changed} tenants changed status.");

        return self::SUCCESS;
    }
}
