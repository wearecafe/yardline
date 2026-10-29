<?php

namespace App\Console\Commands;

use App\Accounting\QuickBooks\PullPayments;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

class PullQuickBooksPayments extends Command
{
    protected $signature = 'yardrunner:quickbooks:pull';

    /** The name it had before the rename, kept so old habits and docs still work. */
    protected $aliases = ['yardline:quickbooks:pull'];

    protected $description = 'Bring payments entered in QuickBooks against YardRunner invoices into YardRunner';

    public function handle(PullPayments $pull): int
    {
        $tenants = Integration::withoutGlobalScopes()
            ->where('provider', IntegrationProvider::QuickBooks->value)
            ->where('status', IntegrationStatus::Connected->value)
            ->pluck('tenant_id');

        foreach (Tenant::query()->whereIn('id', $tenants)->get() as $tenant) {
            CurrentTenant::run($tenant, function () use ($pull, $tenant) {
                $integration = Integration::activeFor(IntegrationProvider::QuickBooks);

                if ($integration === null) {
                    return;
                }

                try {
                    $count = $pull->handle($integration);
                    $this->line("{$tenant->name}: {$count} payments from QuickBooks.");
                } catch (Throwable $e) {
                    report($e);
                    $this->error("{$tenant->name}: {$e->getMessage()}");
                }
            });
        }

        return self::SUCCESS;
    }
}
