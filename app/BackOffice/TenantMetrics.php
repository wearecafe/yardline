<?php

namespace App\BackOffice;

use App\Enums\IntegrationStatus;
use App\Enums\OrderStatus;
use App\Enums\RentalStatus;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Activity figures across tenants for the back office.
 *
 * Reads tables directly (not through tenant-scoped models): staff have no
 * tenant, and every figure here is grouped by tenant_id. Each method takes
 * an optional list of tenant IDs to limit the work to one page of tenants.
 */
final class TenantMetrics
{
    /** Order statuses that count as a sale. */
    private const SOLD = [OrderStatus::Confirmed->value, OrderStatus::Fulfilled->value];

    /** Rentals that actually went ahead (or will). */
    private const BOOKED = [RentalStatus::Reserved->value, RentalStatus::Out->value, RentalStatus::Returned->value];

    /**
     * Sales orders confirmed in a window, per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, array{orders: int, sales_cents: int, shopify: int}>
     */
    public function orders(CarbonImmutable $from, CarbonImmutable $to, ?array $tenantIds = null): array
    {
        $rows = $this->limit(DB::table('orders'), $tenantIds)
            ->where('type', 'sale')
            ->whereIn('status', self::SOLD)
            ->whereBetween('confirmed_at', [$from, $to])
            ->groupBy('tenant_id')
            ->select('tenant_id')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(subtotal_cents), 0) as sales_cents')
            ->selectRaw("sum(case when source = 'shopify' then 1 else 0 end) as shopify")
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->tenant_id] = [
                'orders' => (int) $row->orders,
                'sales_cents' => (int) $row->sales_cents,
                'shopify' => (int) $row->shopify,
            ];
        }

        return $out;
    }

    /**
     * Orders confirmed per day, split by where they came from.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, array<string, array{shopify: int, other: int}>> tenant => Y-m-d => counts
     */
    public function dailyOrders(CarbonImmutable $from, CarbonImmutable $to, ?array $tenantIds = null): array
    {
        $rows = $this->limit(DB::table('orders'), $tenantIds)
            ->where('type', 'sale')
            ->whereIn('status', self::SOLD)
            ->whereBetween('confirmed_at', [$from, $to])
            ->selectRaw('tenant_id, date(confirmed_at) as day')
            ->selectRaw("sum(case when source = 'shopify' then 1 else 0 end) as shopify")
            ->selectRaw("sum(case when source = 'shopify' then 0 else 1 end) as other")
            ->groupByRaw('tenant_id, date(confirmed_at)')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $out[(int) $row->tenant_id][$day] = ['shopify' => (int) $row->shopify, 'other' => (int) $row->other];
        }

        return $out;
    }

    /**
     * Platform-wide totals per day: orders, sales and rentals booked.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<string, array{orders: int, sales_cents: int, rentals: int}> Y-m-d => totals
     */
    public function dailyVolume(CarbonImmutable $from, CarbonImmutable $to, ?array $tenantIds = null): array
    {
        $out = [];

        $orders = $this->limit(DB::table('orders'), $tenantIds)
            ->where('type', 'sale')
            ->whereIn('status', self::SOLD)
            ->whereBetween('confirmed_at', [$from, $to])
            ->selectRaw('date(confirmed_at) as day, count(*) as orders, coalesce(sum(subtotal_cents), 0) as sales_cents')
            ->groupByRaw('date(confirmed_at)')
            ->get();

        foreach ($orders as $row) {
            $day = substr((string) $row->day, 0, 10);
            $out[$day] = ['orders' => (int) $row->orders, 'sales_cents' => (int) $row->sales_cents, 'rentals' => 0];
        }

        $rentals = $this->limit(DB::table('rentals'), $tenantIds)
            ->whereIn('status', self::BOOKED)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('date(created_at) as day, count(*) as rentals')
            ->groupByRaw('date(created_at)')
            ->get();

        foreach ($rentals as $row) {
            $day = substr((string) $row->day, 0, 10);
            $out[$day] ??= ['orders' => 0, 'sales_cents' => 0, 'rentals' => 0];
            $out[$day]['rentals'] = (int) $row->rentals;
        }

        return $out;
    }

    /**
     * Rentals booked in a window, per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, int>
     */
    public function rentalsBooked(CarbonImmutable $from, CarbonImmutable $to, ?array $tenantIds = null): array
    {
        return $this->countBy(
            $this->limit(DB::table('rentals'), $tenantIds)
                ->whereIn('status', self::BOOKED)
                ->whereBetween('created_at', [$from, $to]),
        );
    }

    /**
     * Rentals out on rent right now, per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, int>
     */
    public function rentalsOut(?array $tenantIds = null): array
    {
        return $this->countBy(
            $this->limit(DB::table('rentals'), $tenantIds)->where('status', RentalStatus::Out->value),
        );
    }

    /**
     * People who can sign in, per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, int>
     */
    public function users(?array $tenantIds = null): array
    {
        return $this->countBy($this->limit(DB::table('users'), $tenantIds)->whereNotNull('tenant_id'));
    }

    /**
     * People who used Yardline on any day in the window, per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, int>
     */
    public function activeUsers(CarbonImmutable $from, CarbonImmutable $to, ?array $tenantIds = null): array
    {
        $rows = $this->limit(DB::table('user_activity_days'), $tenantIds)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('tenant_id')
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, count(distinct user_id) as n')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->tenant_id] = (int) $row->n;
        }

        return $out;
    }

    /**
     * Yards per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, int>
     */
    public function yards(?array $tenantIds = null): array
    {
        return $this->countBy($this->limit(DB::table('locations'), $tenantIds));
    }

    /**
     * Each tenant's Shopify and QuickBooks connections.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, array<string, array{status: string, account: string|null, last_synced_at: string|null, last_error: string|null, updated_at: string|null}>>
     */
    public function integrations(?array $tenantIds = null): array
    {
        $rows = $this->limit(DB::table('integrations'), $tenantIds)
            ->get(['tenant_id', 'provider', 'status', 'external_account', 'last_synced_at', 'last_error', 'updated_at']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->tenant_id][(string) $row->provider] = [
                'status' => (string) $row->status,
                'account' => $row->external_account === null ? null : (string) $row->external_account,
                'last_synced_at' => $row->last_synced_at === null ? null : (string) $row->last_synced_at,
                'last_error' => $row->last_error === null ? null : (string) $row->last_error,
                'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ];
        }

        return $out;
    }

    /**
     * QuickBooks records that failed or are waiting to send, per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, array{failed: int, waiting: int, oldest_failure: string|null}>
     */
    public function quickBooksBacklog(?array $tenantIds = null): array
    {
        $rows = $this->limit(DB::table('quickbooks_syncs'), $tenantIds)
            ->whereIn('status', ['failed', 'waiting'])
            ->groupBy('tenant_id')
            ->select('tenant_id')
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw("sum(case when status = 'waiting' then 1 else 0 end) as waiting")
            ->selectRaw("min(case when status = 'failed' then updated_at end) as oldest_failure")
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->tenant_id] = [
                'failed' => (int) $row->failed,
                'waiting' => (int) $row->waiting,
                'oldest_failure' => $row->oldest_failure === null ? null : (string) $row->oldest_failure,
            ];
        }

        return $out;
    }

    /**
     * Webhooks that failed (or failed later in their job) since a time,
     * per tenant.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, array{count: int, latest: string|null, provider: string}>
     */
    public function webhookFailures(CarbonImmutable $since, ?array $tenantIds = null): array
    {
        $rows = $this->limit(DB::table('webhook_deliveries'), $tenantIds)
            ->whereNotNull('tenant_id')
            ->where('outcome', WebhookDelivery::FAILED)
            ->where('received_at', '>=', $since)
            ->orderByDesc('received_at')
            ->get(['tenant_id', 'provider', 'detail']);

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->tenant_id;
            $out[$id] ??= ['count' => 0, 'latest' => $row->detail === null ? null : (string) $row->detail, 'provider' => (string) $row->provider];
            $out[$id]['count']++;
        }

        return $out;
    }

    /**
     * How much each tenant used each part of Yardline in a window. A module
     * a tenant hasn't connected (Shopify, QuickBooks) is left out, so it can
     * be shown as off rather than unused.
     *
     * @param  array<int, mixed>|null  $tenantIds
     * @return array<int, array<string, int>> tenant => module => actions
     */
    public function moduleUsage(CarbonImmutable $from, CarbonImmutable $to, ?array $tenantIds = null): array
    {
        $counts = [
            'inventory' => $this->countBy($this->limit(DB::table('stock_movements'), $tenantIds)->whereBetween('created_at', [$from, $to])),
            'shopify' => $this->countBy($this->limit(DB::table('orders'), $tenantIds)->where('source', 'shopify')->whereBetween('created_at', [$from, $to])),
            'quickbooks' => $this->countBy($this->limit(DB::table('quickbooks_syncs'), $tenantIds)->where('status', 'synced')->whereBetween('synced_at', [$from, $to])),
            'dispatch' => $this->countBy($this->limit(DB::table('stops'), $tenantIds)->whereBetween('created_at', [$from, $to])),
            'rentals' => $this->countBy($this->limit(DB::table('rentals'), $tenantIds)->whereBetween('created_at', [$from, $to])),
            'counter' => $this->countBy($this->limit(DB::table('orders'), $tenantIds)->where('source', 'counter')->whereBetween('created_at', [$from, $to])),
            'portal' => $this->countBy($this->limit(DB::table('orders'), $tenantIds)->where('source', 'portal')->whereBetween('created_at', [$from, $to])),
        ];

        $connected = [];

        foreach ($this->integrations($tenantIds) as $tenantId => $providers) {
            foreach ($providers as $provider => $integration) {
                if ($integration['status'] !== IntegrationStatus::Paused->value) {
                    $connected[$tenantId][$provider] = true;
                }
            }
        }

        $out = [];

        foreach ($counts as $module => $byTenant) {
            foreach ($byTenant as $tenantId => $n) {
                $out[$tenantId][$module] = $n;
            }
        }

        // Connected integrations with no traffic still show, as zero.
        foreach ($connected as $tenantId => $providers) {
            foreach (array_keys($providers) as $provider) {
                $out[$tenantId][$provider] ??= 0;
            }
        }

        // Integrations that aren't connected show as off.
        foreach ($out as $tenantId => $modules) {
            foreach (['shopify', 'quickbooks'] as $provider) {
                if (! isset($connected[$tenantId][$provider])) {
                    unset($out[$tenantId][$provider]);
                }
            }
        }

        return $out;
    }

    /**
     * The months each tenant was active in (any user signed in), for
     * retention.
     *
     * @param  array<int, mixed>  $tenantIds
     * @return array<int, array<string, true>> tenant => Y-m => true
     */
    public function activeMonths(array $tenantIds, CarbonImmutable $from): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $rows = DB::table('user_activity_days')
            ->whereIn('tenant_id', $tenantIds)
            ->where('day', '>=', $from->toDateString())
            ->select('tenant_id', 'day')
            ->distinct()
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->tenant_id][substr((string) $row->day, 0, 7)] = true;
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>|null  $tenantIds
     */
    private function limit(Builder $query, ?array $tenantIds): Builder
    {
        return $tenantIds === null ? $query : $query->whereIn('tenant_id', $tenantIds);
    }

    /**
     * @return array<int, int>
     */
    private function countBy(Builder $query): array
    {
        $out = [];

        foreach ($query->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as n')->get() as $row) {
            $out[(int) $row->tenant_id] = (int) $row->n;
        }

        return $out;
    }
}
