<?php

namespace App\Http\Controllers\Admin;

use App\BackOffice\TenantHealth;
use App\BackOffice\TenantMetrics;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activity across tenants: volume per week, which parts of YardRunner each
 * tenant uses, engagement and churn risk, and retention by signup month.
 */
class ReportController extends Controller
{
    private const WEEKS = 12;

    private const COHORTS = 6;

    public function __invoke(Request $request, TenantMetrics $metrics): Response
    {
        /** @var array<string, string> $plans */
        $plans = config('yardline.back_office.plans', []);

        $filters = $request->validate([
            'plan' => ['nullable', Rule::in(array_keys($plans))],
            'status' => ['nullable', Rule::in(['live', 'active', 'trial'])],
        ]);

        $status = $filters['status'] ?? 'live';

        $tenants = Tenant::query()
            ->when($filters['plan'] ?? null, fn ($q, $plan) => $q->where('plan', $plan))
            ->get()
            ->filter(fn (Tenant $t) => match ($status) {
                'active' => $t->status->isBillable(),
                'trial' => $t->status === TenantStatus::Trial,
                default => $t->status->isLive(),
            })
            ->values();

        $ids = $tenants->modelKeys();
        $now = CarbonImmutable::now();

        return Inertia::render('admin/reports', [
            'weeks' => $this->weeks($metrics, $ids, $now),
            'modules' => collect(OverviewController::MODULES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'usage' => $this->usage($metrics, $tenants->all(), $now),
            'engagement' => $this->engagement($metrics, $tenants->all(), $now),
            'cohorts' => $this->cohorts($metrics, $now),
            'filters' => ['plan' => $filters['plan'] ?? null, 'status' => $status],
            'plans' => collect($plans)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    /**
     * Orders, sales and rentals per week (weeks start Monday).
     *
     * @param  array<int, mixed>  $ids
     * @return list<array{start: string, orders: int, sales_cents: int, rentals: int}>
     */
    private function weeks(TenantMetrics $metrics, array $ids, CarbonImmutable $now): array
    {
        $first = $now->startOfWeek()->subWeeks(self::WEEKS - 1);
        $daily = $ids === [] ? [] : $metrics->dailyVolume($first, $now, $ids);

        $weeks = [];

        for ($i = 0; $i < self::WEEKS; $i++) {
            $start = $first->addWeeks($i);
            $week = ['start' => $start->toDateString(), 'orders' => 0, 'sales_cents' => 0, 'rentals' => 0];

            for ($d = 0; $d < 7; $d++) {
                $day = $daily[$start->addDays($d)->toDateString()] ?? null;

                if ($day !== null) {
                    $week['orders'] += $day['orders'];
                    $week['sales_cents'] += $day['sales_cents'];
                    $week['rentals'] += $day['rentals'];
                }
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * Actions per week in each module over the last four weeks, busiest
     * tenants first. Null when the module is switched off or not connected.
     *
     * @param  array<int, Tenant>  $tenants
     * @return list<array{id: int, name: string, cells: list<int|null>}>
     */
    private function usage(TenantMetrics $metrics, array $tenants, CarbonImmutable $now): array
    {
        $ids = array_map(fn (Tenant $t) => $t->id, $tenants);
        $usage = $ids === [] ? [] : $metrics->moduleUsage($now->subDays(28), $now, $ids);
        /** @var array<string, string> $modules */
        $modules = config('yardline.back_office.modules', []);
        $switchable = array_keys($modules);

        $rows = array_map(function (Tenant $t) use ($usage, $switchable) {
            $cells = [];

            foreach (array_keys(OverviewController::MODULES) as $module) {
                $off = in_array($module, $switchable, true) && ! $t->hasModule($module);
                $connectedOrUsed = ! in_array($module, ['quickbooks'], true) || array_key_exists($module, $usage[$t->id] ?? []);

                $cells[] = $off || ! $connectedOrUsed ? null : (int) round(($usage[$t->id][$module] ?? 0) / 4);
            }

            return ['id' => $t->id, 'name' => $t->name, 'cells' => $cells];
        }, $tenants);

        usort($rows, fn (array $a, array $b) => array_sum(array_map('intval', $b['cells'])) <=> array_sum(array_map('intval', $a['cells'])));

        return array_slice($rows, 0, 30);
    }

    /**
     * @param  array<int, Tenant>  $tenants
     * @return list<array<string, mixed>>
     */
    private function engagement(TenantMetrics $metrics, array $tenants, CarbonImmutable $now): array
    {
        $ids = array_map(fn (Tenant $t) => $t->id, $tenants);

        if ($ids === []) {
            return [];
        }

        $orders = $metrics->orders($now->subDays(30), $now, $ids);
        $prior = $metrics->orders($now->subDays(60), $now->subDays(30), $ids);
        $weekly = $metrics->activeUsers($now->subDays(6), $now, $ids);
        $monthly = $metrics->activeUsers($now->subDays(29), $now, $ids);
        $users = $metrics->users($ids);

        $rows = array_map(function (Tenant $t) use ($orders, $prior, $weekly, $monthly, $users) {
            $now = $orders[$t->id]['orders'] ?? 0;
            $before = $prior[$t->id]['orders'] ?? 0;

            return [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status->value,
                'weekly_users' => $weekly[$t->id] ?? 0,
                'monthly_users' => $monthly[$t->id] ?? 0,
                'users' => $users[$t->id] ?? 0,
                'orders' => $now,
                'change' => TenantHealth::change($now, $before),
                'last_active_at' => $t->last_active_at?->toIso8601String(),
                'risk' => TenantHealth::risk($t, $now, $before, $weekly[$t->id] ?? 0, $users[$t->id] ?? 0),
            ];
        }, $tenants);

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($rows, fn (array $a, array $b) => $rank[$a['risk']] <=> $rank[$b['risk']] ?: $a['orders'] <=> $b['orders']);

        return $rows;
    }

    /**
     * For tenants who signed up in each of the last six months, the share
     * still using YardRunner (anyone signed in) one to five months later.
     *
     * @return list<array{month: string, size: int, cells: list<int|null>}>
     */
    private function cohorts(TenantMetrics $metrics, CarbonImmutable $now): array
    {
        $first = $now->startOfMonth()->subMonths(self::COHORTS - 1);

        $tenants = Tenant::query()
            ->where('created_at', '>=', $first)
            ->get(['id', 'created_at']);

        $active = $metrics->activeMonths($tenants->modelKeys(), $first);
        $out = [];

        for ($i = 0; $i < self::COHORTS; $i++) {
            $month = $first->addMonths($i);
            $cohort = $tenants->filter(fn (Tenant $t) => $t->created_at?->format('Y-m') === $month->format('Y-m'));
            $cells = [];

            for ($k = 1; $k <= 5; $k++) {
                $later = $month->addMonths($k);

                if ($later->gt($now) || $cohort->isEmpty()) {
                    $cells[] = null;

                    continue;
                }

                $still = $cohort->filter(fn (Tenant $t) => isset($active[$t->id][$later->format('Y-m')]))->count();
                $cells[] = (int) round($still / $cohort->count() * 100);
            }

            $out[] = ['month' => $month->format('M Y'), 'size' => $cohort->count(), 'cells' => $cells];
        }

        return $out;
    }
}
