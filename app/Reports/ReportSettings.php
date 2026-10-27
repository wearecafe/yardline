<?php

namespace App\Reports;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Who sees the profit report, kept in tenants.settings['reports']. The
 * owner always does; admins only when the owner lets them. Staff, order
 * pickers and drivers never see costs or margins.
 */
final readonly class ReportSettings
{
    public function __construct(public bool $adminsCanViewProfit = false) {}

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['reports'] ?? [];

        return new self(adminsCanViewProfit: (bool) ($v['admins_can_view_profit'] ?? false));
    }

    public function canViewProfit(?User $user): bool
    {
        return match ($user?->role) {
            UserRole::Owner => true,
            UserRole::Admin => $this->adminsCanViewProfit,
            default => false,
        };
    }

    /**
     * @return array{admins_can_view_profit: bool}
     */
    public function toArray(): array
    {
        return ['admins_can_view_profit' => $this->adminsCanViewProfit];
    }
}
