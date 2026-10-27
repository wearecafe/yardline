<?php

namespace App\Http\Controllers\Admin;

use App\BackOffice\Attention;
use App\BackOffice\TenantHealth;
use App\BackOffice\TenantMetrics;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The back office front page: the numbers that matter across every
 * tenant, who's busiest, and what needs attention.
 */
class OverviewController extends Controller
{
    /** Modules shown under adoption, in order. */
    public const MODULES = [
        'inventory' => 'Inventory and stock',
        'quickbooks' => 'QuickBooks Online sync',
        'dispatch' => 'Dispatch and delivery',
        'rentals' => 'Equipment rentals',
        'counter' => 'Counter sales screen',
        'portal' => 'Contractor portal',
    ];

    public function __invoke(TenantMetrics $metrics, Attention $attention): Response
    {
        $now = CarbonImmutable::now();
        $monthAgo = $now->subDays(30);
        $twoMonthsAgo = $now->subDays(60);

        $tenants = Tenant::query()->get();
        $live = $tenants->filter(fn (Tenant $t) => $t->status->isLive());
        $liveIds = $live->modelKeys();

        $orders = $metrics->orders($monthAgo, $now);
        $prior = $metrics->orders($twoMonthsAgo, $monthAgo);
        $sum = fn (array $rows, string $key): int => array_sum(array_column($rows, $key));

        $ordersNow = $sum($orders, 'orders');

        return Inertia::render('admin/overview', [
            'kpis' => [
                'active_tenants' => $tenants->filter(fn (Tenant $t) => $t->status->isBillable())->count(),
                'new_tenants' => $tenants->filter(fn (Tenant $t) => $t->created_at !== null && $t->created_at->gte($now->startOfMonth()))->count(),
                'trials' => $tenants->where('status', TenantStatus::Trial)->count(),
                'trials_ending' => $tenants->filter(fn (Tenant $t) => $t->status === TenantStatus::Trial && $t->trial_ends_at !== null && $t->trial_ends_at->lte($now->addDays(7)))->count(),
                'mrr_cents' => (int) $tenants->filter(fn (Tenant $t) => $t->status->isBillable())->sum('monthly_price_cents'),
                'sales_cents' => $sum($orders, 'sales_cents'),
                'prior_sales_cents' => $sum($prior, 'sales_cents'),
                'orders' => $ordersNow,
                'prior_orders' => $sum($prior, 'orders'),
                'rentals_out' => array_sum($metrics->rentalsOut()),
            ],
            'busiest' => $this->busiest($metrics, $live->all(), $orders, $prior, $now),
            'attention' => $attention->items(),
            'growth' => $this->growth($tenants->all(), $now),
            'adoption' => $this->adoption($metrics, $liveIds, $now),
        ]);
    }

    /**
     * The ten live tenants with the most orders in the last 30 days.
     *
     * @param  array<int, Tenant>  $live
     * @param  array<int, array{orders: int, sales_cents: int}>  $orders
     * @param  array<int, array{orders: int, sales_cents: int}>  $prior
     * @return list<array<string, mixed>>
     */
    private function busiest(TenantMetrics $metrics, array $live, array $orders, array $prior, CarbonImmutable $now): array
    {
        usort($live, fn (Tenant $a, Tenant $b) => ($orders[$b->id]['orders'] ?? 0) <=> ($orders[$a->id]['orders'] ?? 0)
            ?: ($b->last_active_at?->getTimestamp() ?? 0) <=> ($a->last_active_at?->getTimestamp() ?? 0));

        $top = array_slice($live, 0, 10);
        $ids = array_map(fn (Tenant $t) => $t->id, $top);

        $daily = $metrics->dailyOrders($now->subDays(13)->startOfDay(), $now, $ids);
        $rentals = $metrics->rentalsBooked($now->subDays(30), $now, $ids);
        $weekly = $metrics->activeUsers($now->subDays(6), $now, $ids);
        $users = $metrics->users($ids);
        $yards = $metrics->yards($ids);
        $integrations = $metrics->integrations($ids);
        $quickBooks = $metrics->quickBooksBacklog($ids);
        $failures = $metrics->webhookFailures($now->subDay(), $ids);

        return array_map(function (Tenant $t) use ($daily, $rentals, $weekly, $users, $yards, $integrations, $quickBooks, $failures, $orders, $prior, $now) {
            $spark = [];

            for ($i = 13; $i >= 0; $i--) {
                $day = $now->subDays($i)->toDateString();
                $spark[] = $daily[$t->id][$day] ?? 0;
            }

            return [
                'id' => $t->id,
                'name' => $t->name,
                'yards' => $yards[$t->id] ?? 0,
                'orders' => $orders[$t->id]['orders'] ?? 0,
                'spark' => $spark,
                'rentals' => $rentals[$t->id] ?? 0,
                'weekly_users' => $weekly[$t->id] ?? 0,
                'users' => $users[$t->id] ?? 0,
                'last_active_at' => $t->last_active_at?->toIso8601String(),
                'health' => TenantHealth::of(
                    $t,
                    $orders[$t->id]['orders'] ?? 0,
                    $prior[$t->id]['orders'] ?? 0,
                    $integrations[$t->id] ?? [],
                    $quickBooks[$t->id] ?? null,
                    $failures[$t->id]['count'] ?? 0,
                ),
            ];
        }, $top);
    }

    /**
     * New and cancelled tenants per month for the last 12 months.
     *
     * @param  array<int, Tenant>  $tenants
     * @return list<array{month: string, new: int, churned: int}>
     */
    private function growth(array $tenants, CarbonImmutable $now): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = $now->startOfMonth()->subMonths($i);
            $months[$month->format('Y-m')] = ['month' => $month->format('M'), 'new' => 0, 'churned' => 0];
        }

        foreach ($tenants as $tenant) {
            $created = $tenant->created_at?->format('Y-m');

            if ($created !== null && isset($months[$created])) {
                $months[$created]['new']++;
            }

            $left = $tenant->status === TenantStatus::Cancelled ? $tenant->status_changed_at?->format('Y-m') : null;

            if ($left !== null && isset($months[$left])) {
                $months[$left]['churned']++;
            }
        }

        return array_values($months);
    }

    /**
     * Share of live tenants that used each part of YardRunner this week.
     *
     * @param  array<int, mixed>  $liveIds
     * @return list<array{key: string, label: string, percent: int}>
     */
    private function adoption(TenantMetrics $metrics, array $liveIds, CarbonImmutable $now): array
    {
        $usage = $metrics->moduleUsage($now->subDays(7), $now, $liveIds);
        $total = count($liveIds);
        $out = [];

        foreach (self::MODULES as $key => $label) {
            $using = 0;

            foreach ($usage as $modules) {
                if (($modules[$key] ?? 0) > 0) {
                    $using++;
                }
            }

            $out[] = ['key' => $key, 'label' => $label, 'percent' => $total > 0 ? (int) round($using / $total * 100) : 0];
        }

        return $out;
    }
}
