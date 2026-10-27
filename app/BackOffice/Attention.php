<?php

namespace App\BackOffice;

use App\Enums\IntegrationStatus;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What staff should look at today: broken connections, failed syncs and
 * jobs, trials about to end, accounts past due and tenants gone quiet.
 */
final class Attention
{
    private const PROVIDERS = ['shopify' => 'Shopify', 'quickbooks' => 'QuickBooks', 'stripe' => 'Stripe'];

    public function __construct(private TenantMetrics $metrics) {}

    /**
     * @return list<array{tone: 'danger'|'warning', title: string, detail: string, tenant: array{id: int, name: string}|null, link: 'tenant'|'integrations'}>
     */
    public function items(): array
    {
        $tenants = Tenant::query()
            ->whereNotIn('status', [TenantStatus::Cancelled->value, TenantStatus::Suspended->value])
            ->get(['id', 'name', 'status', 'trial_ends_at', 'last_active_at', 'created_at'])
            ->keyBy('id');

        $items = [];
        $since = now()->subDay();

        foreach ($this->metrics->integrations($tenants->keys()->all()) as $tenantId => $providers) {
            foreach ($providers as $provider => $integration) {
                if ($integration['status'] === IntegrationStatus::Error->value) {
                    $items[] = $this->item('danger', (self::PROVIDERS[$provider] ?? Str::headline($provider)).' connection has an error', $integration['last_error'] ?? 'Ask the tenant to reconnect it.', $tenants->get($tenantId), 'integrations');
                }
            }
        }

        foreach ($this->metrics->quickBooksBacklog($tenants->keys()->all()) as $tenantId => $backlog) {
            if ($backlog['failed'] > 0) {
                $items[] = $this->item('danger', $this->plural($backlog['failed'], 'QuickBooks record').' failed to send', 'Invoices, payments or credits are missing from their books.', $tenants->get($tenantId), 'integrations');
            }
        }

        foreach ($this->metrics->webhookFailures($since, $tenants->keys()->all()) as $tenantId => $failures) {
            $items[] = $this->item('danger', $this->plural($failures['count'], (self::PROVIDERS[$failures['provider']] ?? 'Webhook').' webhook').' failed today', $failures['latest'] ?? 'See integration health.', $tenants->get($tenantId), 'integrations');
        }

        $rejected = WebhookDelivery::query()
            ->where('outcome', WebhookDelivery::REJECTED)
            ->where('received_at', '>=', $since)
            ->count();

        if ($rejected > 0) {
            $items[] = $this->item('warning', $this->plural($rejected, 'webhook').' rejected today', 'The signature didn’t match. Check the webhook secrets in the environment.', null, 'integrations');
        }

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        if ($failedJobs > 0) {
            $items[] = $this->item('warning', $this->plural($failedJobs, 'background job').' failed', 'Retry or clear them under integration health.', null, 'integrations');
        }

        $soon = now()->addDays(7)->endOfDay();

        foreach ($tenants as $tenant) {
            if ($tenant->status === TenantStatus::Trial && $tenant->trial_ends_at !== null && $tenant->trial_ends_at->lte($soon)) {
                $ended = $tenant->trial_ends_at->isPast() && ! $tenant->trial_ends_at->isToday();
                $items[] = $this->item('warning', $ended ? 'Trial has ended' : 'Trial ends '.$tenant->trial_ends_at->format('M j'), 'Time for a conversion call, or extend the trial.', $tenant, 'tenant');
            }

            if ($tenant->status === TenantStatus::PastDue) {
                $items[] = $this->item('warning', 'Payment past due', 'Marked past due in the back office.', $tenant, 'tenant');
            }

            if (in_array($tenant->status, [TenantStatus::Active, TenantStatus::PastDue], true)
                && ($tenant->last_active_at === null || $tenant->last_active_at->lt(now()->subDays(TenantHealth::DORMANT_DAYS)))) {
                $items[] = $this->item('warning', $tenant->last_active_at === null ? 'Hasn’t used Yardline yet' : 'No one has signed in for '.TenantHealth::DORMANT_DAYS.' days', 'A paying tenant gone quiet is a churn risk.', $tenant, 'tenant');
            }
        }

        usort($items, fn (array $a, array $b) => ($a['tone'] === 'danger' ? 0 : 1) <=> ($b['tone'] === 'danger' ? 0 : 1));

        return $items;
    }

    /**
     * @param  'danger'|'warning'  $tone
     * @param  'tenant'|'integrations'  $link
     * @return array{tone: 'danger'|'warning', title: string, detail: string, tenant: array{id: int, name: string}|null, link: 'tenant'|'integrations'}
     */
    private function item(string $tone, string $title, string $detail, ?Tenant $tenant, string $link): array
    {
        return [
            'tone' => $tone,
            'title' => $title,
            'detail' => Str::limit($detail, 160),
            'tenant' => $tenant === null ? null : ['id' => $tenant->id, 'name' => $tenant->name],
            'link' => $link,
        ];
    }

    private function plural(int $n, string $noun): string
    {
        return $n.' '.Str::plural($noun, $n);
    }
}
