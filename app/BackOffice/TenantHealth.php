<?php

namespace App\BackOffice;

use App\Enums\IntegrationStatus;
use App\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * A one-word read on how a tenant is doing, for the back office lists,
 * and how likely they are to leave.
 *
 * Worst thing first: a cancelled or suspended account, then broken syncs,
 * then billing, then whether anyone is using Yardline.
 */
final class TenantHealth
{
    /** Days without anyone signing in before a tenant looks dormant. */
    public const DORMANT_DAYS = 14;

    /**
     * @param  array<string, array{status: string}>  $integrations  provider => connection
     * @param  array{failed: int, waiting: int, oldest_failure: string|null}|null  $quickBooks
     * @return array{label: string, tone: 'success'|'info'|'warning'|'danger'|'neutral'}
     */
    public static function of(Tenant $tenant, int $orders, int $priorOrders, array $integrations = [], ?array $quickBooks = null, int $webhookFailures = 0): array
    {
        if ($tenant->status === TenantStatus::Cancelled) {
            return ['label' => 'Cancelled', 'tone' => 'neutral'];
        }

        if ($tenant->status === TenantStatus::Suspended) {
            return ['label' => 'Suspended', 'tone' => 'danger'];
        }

        foreach ($integrations as $integration) {
            if ($integration['status'] === IntegrationStatus::Error->value) {
                return ['label' => 'Sync failing', 'tone' => 'danger'];
            }
        }

        if (($quickBooks['failed'] ?? 0) > 0 || $webhookFailures > 0) {
            return ['label' => 'Sync failing', 'tone' => 'danger'];
        }

        if ($tenant->status === TenantStatus::PastDue) {
            return ['label' => 'Past due', 'tone' => 'warning'];
        }

        if ($tenant->last_active_at === null) {
            return ['label' => 'Never used', 'tone' => 'warning'];
        }

        if ($tenant->last_active_at->lt(now()->subDays(self::DORMANT_DAYS))) {
            return ['label' => 'No activity in '.self::DORMANT_DAYS.' days', 'tone' => 'warning'];
        }

        if (self::falling($orders, $priorOrders)) {
            return ['label' => 'Usage falling', 'tone' => 'warning'];
        }

        if ($tenant->status === TenantStatus::Trial) {
            $day = (int) $tenant->created_at?->startOfDay()->diffInDays(now()->startOfDay()) + 1;

            return ['label' => "Trial · day {$day}", 'tone' => 'info'];
        }

        return ['label' => 'Healthy', 'tone' => 'success'];
    }

    /**
     * How likely a tenant is to leave: high when nobody uses Yardline or
     * orders have halved, medium when orders are down a fifth or most
     * people with logins don't use it.
     *
     * @return 'high'|'medium'|'low'
     */
    public static function risk(Tenant $tenant, int $orders, int $priorOrders, int $weeklyUsers, int $users): string
    {
        $dormant = $tenant->last_active_at === null || $tenant->last_active_at->lt(now()->subDays(self::DORMANT_DAYS));
        $change = self::change($orders, $priorOrders);

        if ($dormant || $tenant->status === TenantStatus::PastDue || ($change !== null && $change <= -0.5)) {
            return 'high';
        }

        if (($change !== null && $change <= -0.2) || ($users > 0 && $weeklyUsers / $users < 0.5)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Change in orders against the window before, or null when there's too
     * little to compare.
     */
    public static function change(int $orders, int $priorOrders): ?float
    {
        if ($priorOrders < 5) {
            return null;
        }

        return ($orders - $priorOrders) / $priorOrders;
    }

    private static function falling(int $orders, int $priorOrders): bool
    {
        $change = self::change($orders, $priorOrders);

        return $change !== null && $priorOrders >= 10 && $change <= -0.3;
    }
}
