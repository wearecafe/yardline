<?php

namespace App\Reports;

use App\Access\AccessSettings;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Who sees the profit report: anyone holding See gross-profit P&L. The
 * owner always does; admins when the owner gives the Admin role that
 * permission (Settings → Roles, or the switch on Settings → Users).
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
        return new self(AccessSettings::for($tenant)->roleGives(UserRole::Admin, Permission::SeeProfit));
    }

    public function canViewProfit(?User $user): bool
    {
        return $user !== null && $user->hasPermission(Permission::SeeProfit);
    }

    /**
     * @return array{admins_can_view_profit: bool}
     */
    public function toArray(): array
    {
        return ['admins_can_view_profit' => $this->adminsCanViewProfit];
    }
}
