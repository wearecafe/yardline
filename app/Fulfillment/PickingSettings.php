<?php

namespace App\Fulfillment;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;

/**
 * Which fulfillment stages a yard uses and where it wants a quality
 * check, kept in tenants.settings['picking'].
 *
 * New and Delivered / Picked up are always on. Picking, Picked, Loaded
 * and Out for delivery can each be switched off; an order skips a stage
 * that's off. A sign-off can be required at Picked, Loaded or both,
 * only while that stage is on.
 */
final readonly class PickingSettings
{
    /** Stages a business can switch on or off. */
    public const STAGES = [
        'picking' => 'Picking',
        'picked' => 'Picked',
        'loaded' => 'Loaded',
        'out' => 'Out for delivery',
    ];

    /** Stages that can require a sign-off. */
    public const CHECKED = ['picked', 'loaded'];

    /**
     * @param  array<string, bool>  $stages
     * @param  array<string, array{required: bool, roles: list<UserRole>, different_user: bool}>  $checks
     */
    public function __construct(
        public array $stages,
        public array $checks,
    ) {}

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['picking'] ?? [];
        $stages = [];

        foreach (array_keys(self::STAGES) as $stage) {
            $stages[$stage] = (bool) ($v['stages'][$stage] ?? true);
        }

        $checks = [];

        foreach (self::CHECKED as $stage) {
            $c = $v['checks'][$stage] ?? [];
            $roles = [];

            foreach ((array) ($c['roles'] ?? [UserRole::Owner->value, UserRole::Admin->value, UserRole::Staff->value]) as $value) {
                $role = UserRole::tryFrom((string) $value);

                if ($role !== null && in_array($role, UserRole::checkers(), true) && ! in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }

            $checks[$stage] = [
                // A sign-off is only ever required while its stage is on.
                'required' => $stages[$stage] && (bool) ($c['required'] ?? false),
                'roles' => $roles,
                'different_user' => (bool) ($c['different_user'] ?? true),
            ];
        }

        return new self($stages, $checks);
    }

    public function stageOn(string $stage): bool
    {
        return $this->stages[$stage] ?? false;
    }

    public function requiresCheck(string $stage): bool
    {
        return $this->checks[$stage]['required'] ?? false;
    }

    /**
     * Roles that can sign off a check at this stage. A load already
     * waiting keeps needing one even if the business has since switched
     * the check off, so this falls back to owner and admin.
     *
     * @return list<UserRole>
     */
    public function checkRoles(string $stage): array
    {
        return ($this->checks[$stage]['roles'] ?? []) ?: [UserRole::Owner, UserRole::Admin];
    }

    public function checkByDifferentUser(string $stage): bool
    {
        return $this->checks[$stage]['different_user'] ?? true;
    }

    /**
     * @return array{stages: array<string, bool>, checks: array<string, array{required: bool, roles: list<string>, different_user: bool}>}
     */
    public function toArray(): array
    {
        return [
            'stages' => $this->stages,
            'checks' => array_map(fn (array $c) => [
                'required' => $c['required'],
                'roles' => array_map(fn (UserRole $role) => $role->value, $c['roles']),
                'different_user' => $c['different_user'],
            ], $this->checks),
        ];
    }
}
