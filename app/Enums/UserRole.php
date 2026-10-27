<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Staff = 'staff';

    /** Signs in to their own run of stops and nothing else. */
    case Driver = 'driver';

    /** Only the owner adds, changes and removes the people who sign in. */
    public function canManageUsers(): bool
    {
        return $this === self::Owner;
    }

    public function canManageBusiness(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /**
     * Roles the owner can give someone from Settings → Users. Drivers are
     * added under Settings → Drivers; there is one owner per business.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Staff];
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Staff => 'Staff',
            self::Driver => 'Driver',
        };
    }

    public function isDriver(): bool
    {
        return $this === self::Driver;
    }
}
