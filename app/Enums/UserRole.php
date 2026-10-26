<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Staff = 'staff';

    public function canManageBusiness(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
