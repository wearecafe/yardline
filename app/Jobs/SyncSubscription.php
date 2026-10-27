<?php

namespace App\Jobs;

use App\Billing\BillingGateway;
use App\Billing\Plan;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings a tenant's Stripe subscription in line with their add-ons and
 * tracked people. It reads the tenant when it runs, so a burst of changes
 * (setting up a crew in Time & attendance) becomes one update.
 */
class SyncSubscription implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(public int $tenantId)
    {
        $this->delay(now()->addSeconds(30));
    }

    public function uniqueId(): string
    {
        return "billing:{$this->tenantId}";
    }

    public function handle(BillingGateway $gateway): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->stripe_subscription_id === null) {
            return;
        }

        $gateway->syncItems($tenant, Plan::for($tenant));
    }
}
