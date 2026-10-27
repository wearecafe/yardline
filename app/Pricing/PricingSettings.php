<?php

namespace App\Pricing;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Pricing rules for the business, kept in tenants.settings['pricing'].
 *
 *  - categoryRulesOverride: the default for price lists that don't choose,
 *    whether a category rule beats a product's price list entry.
 *  - exemptPriceRoles: roles that may set a price by hand on a product
 *    that's exempt from price lists. Empty locks exempt prices for everyone.
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

        return new self(
            categoryRulesOverride: (bool) ($v['category_rules_override'] ?? false),
            exemptPriceRoles: array_key_exists('exempt_price_roles', $v)
                ? array_values(array_filter(array_map(
                    fn ($role) => UserRole::tryFrom((string) $role),
                    (array) $v['exempt_price_roles'],
                ), fn (?UserRole $role) => $role !== null && in_array($role, self::priceSettingRoles(), true)))
                : [UserRole::Owner],
        );
    }

    /**
     * Roles that can set line prices by hand at all, so the only ones the
     * exempt-product permission means anything for.
     *
     * @return list<UserRole>
     */
    public static function priceSettingRoles(): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $role) => $role->canManageBusiness()));
    }

    public function canPriceExempt(?User $user): bool
    {
        return $user !== null && in_array($user->role, $this->exemptPriceRoles, true);
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
