<?php

namespace App\Pricing;

use App\Access\AccessSettings;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Pricing rules for the business, kept in tenants.settings['pricing'].
 *
 *  - categoryRulesOverride: the default for price lists that don't choose,
 *    whether a category rule beats a product's price list entry.
 *  - exemptPriceRoles: roles that give Discount exempt products, so may set
 *    a price by hand on a product that's exempt from price lists. Kept with
 *    the other role permissions (AccessSettings); the owner always may.
 */
final readonly class PricingSettings
{
    /**
     * @param  list<UserRole>  $exemptPriceRoles
     */
    public function __construct(
        public bool $categoryRulesOverride = false,
        public array $exemptPriceRoles = [UserRole::Owner],
    ) {}

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['pricing'] ?? [];
        $access = AccessSettings::for($tenant);

        return new self(
            categoryRulesOverride: (bool) ($v['category_rules_override'] ?? false),
            exemptPriceRoles: array_values(array_filter(
                self::priceSettingRoles($tenant),
                fn (UserRole $role) => $access->roleGives($role, Permission::DiscountExempt),
            )),
        );
    }

    /**
     * Roles that can set line prices by hand at all, so the only ones the
     * exempt-product permission means anything for.
     *
     * @return list<UserRole>
     */
    public static function priceSettingRoles(?Tenant $tenant = null): array
    {
        return AccessSettings::for($tenant ?? CurrentTenant::get())->rolesGiving(Permission::SetManualPrices);
    }

    public function canPriceExempt(?User $user): bool
    {
        return $user !== null && $user->hasPermission(Permission::DiscountExempt);
    }

    /**
     * @return array{category_rules_override: bool, exempt_price_roles: list<string>}
     */
    public function toArray(): array
    {
        return [
            'category_rules_override' => $this->categoryRulesOverride,
            'exempt_price_roles' => array_map(fn (UserRole $role) => $role->value, $this->exemptPriceRoles),
        ];
    }
}
