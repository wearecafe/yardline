<?php

namespace App\Access;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;

/**
 * What each role gets by default in this business, kept in
 * tenants.settings['access'] (Settings → Roles):
 *
 *  - role_permissions: role => the permissions it gives. A role the
 *    business hasn't changed gives Permission::defaultRoles(). The owner
 *    always holds every permission.
 *  - refund_limits: role => the most one refund can be, in cents, or null
 *    for no limit. Above it, someone with Refund above limit approves.
 */
final readonly class AccessSettings
{
    /** Refund limits when the business hasn't set one, in cents. */
    public const DEFAULT_REFUND_LIMITS = [
        'owner' => null,
        'admin' => null,
        'accounts' => null,
        'sales' => 25000,
        'rental_desk' => 25000,
    ];

    /**
     * @param  array<string, list<Permission>>  $rolePermissions  only roles the business changed
     * @param  array<string, int|null>  $refundLimits  only roles the business changed
     */
    public function __construct(
        public array $rolePermissions = [],
        public array $refundLimits = [],
    ) {}

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['access'] ?? [];
        $permissions = [];

        foreach ((array) ($v['role_permissions'] ?? []) as $value => $list) {
            $role = UserRole::fromValue((string) $value);

            if ($role === null || $role === UserRole::Owner) {
                continue;
            }

            $permissions[$role->value] = self::clean(array_values(array_filter(array_map(
                fn ($p) => Permission::tryFrom((string) $p),
                (array) $list,
            ))));
        }

        $limits = [];

        foreach ((array) ($v['refund_limits'] ?? []) as $value => $cents) {
            $role = UserRole::fromValue((string) $value);

            if ($role !== null && $role !== UserRole::Owner) {
                $limits[$role->value] = $cents === null ? null : max(0, (int) $cents);
            }
        }

        return new self($permissions, $limits);
    }

    /**
     * The permissions a role gives in this business.
     *
     * @return list<Permission>
     */
    public function permissionsFor(UserRole $role): array
    {
        if ($role === UserRole::Owner) {
            return Permission::cases();
        }

        if ($role === UserRole::TimeOnly) {
            return [];
        }

        return $this->rolePermissions[$role->value]
            ?? array_values(array_filter(Permission::cases(), fn (Permission $p) => in_array($role, $p->defaultRoles(), true)));
    }

    public function roleGives(UserRole $role, Permission $permission): bool
    {
        return in_array($permission, $this->permissionsFor($role), true);
    }

    /**
     * Roles that give this permission in this business.
     *
     * @return list<UserRole>
     */
    public function rolesGiving(Permission $permission): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $r) => $this->roleGives($r, $permission)));
    }

    /**
     * The most one refund by this role can be, in cents, or null for no
     * limit. Roles without one can't refund.
     */
    public function refundLimit(UserRole $role): ?int
    {
        if ($role === UserRole::Owner) {
            return null;
        }

        if ($role === UserRole::TimeOnly) {
            return 0;
        }

        if (array_key_exists($role->value, $this->refundLimits)) {
            return $this->refundLimits[$role->value];
        }

        return array_key_exists($role->value, self::DEFAULT_REFUND_LIMITS) ? self::DEFAULT_REFUND_LIMITS[$role->value] : 0;
    }

    /**
     * A copy with one role's permissions replaced.
     *
     * @param  list<Permission>  $permissions
     */
    public function withRolePermissions(UserRole $role, array $permissions): self
    {
        if ($role === UserRole::Owner) {
            return $this;
        }

        return new self([...$this->rolePermissions, $role->value => self::clean($permissions)], $this->refundLimits);
    }

    /**
     * A copy with one role's permission switched on or off.
     */
    public function withRolePermission(UserRole $role, Permission $permission, bool $on): self
    {
        $current = array_values(array_filter($this->permissionsFor($role), fn (Permission $p) => $p !== $permission));

        return $this->withRolePermissions($role, $on ? [...$current, $permission] : $current);
    }

    public function withRefundLimit(UserRole $role, ?int $cents): self
    {
        if ($role === UserRole::Owner) {
            return $this;
        }

        return new self($this->rolePermissions, [...$this->refundLimits, $role->value => $cents === null ? null : max(0, $cents)]);
    }

    public function save(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];
        $settings['access'] = $this->toArray();
        $tenant->update(['settings' => $settings]);
    }

    /**
     * @return array{role_permissions: array<string, list<string>>, refund_limits: array<string, int|null>}
     */
    public function toArray(): array
    {
        return [
            'role_permissions' => array_map(
                fn (array $list) => array_map(fn (Permission $p) => $p->value, $list),
                $this->rolePermissions,
            ),
            'refund_limits' => $this->refundLimits,
        ];
    }

    /**
     * Unique, in a stable order, without one that needs another missing.
     *
     * @param  list<Permission>  $permissions
     * @return list<Permission>
     */
    private static function clean(array $permissions): array
    {
        $kept = array_values(array_filter(
            Permission::cases(),
            fn (Permission $p) => in_array($p, $permissions, true),
        ));

        return array_values(array_filter(
            $kept,
            fn (Permission $p) => $p->requires() === null || in_array($p->requires(), $kept, true),
        ));
    }
}
