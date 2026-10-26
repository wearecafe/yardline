<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Staff = 'staff';

    /** Signs in to their own run of stops and nothing else. */
    case Driver = 'driver';

    public function canManageBusiness(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function isDriver(): bool
    {
        return $this === self::Driver;
    }
}
