<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoYardSeeder;

/**
 * The fictional demo yard that can live in production alongside real yards.
 */
final class Demo
{
    public static function enabled(): bool
    {
        return (bool) config('yardline.demo');
    }

    public static function tenant(): ?Tenant
    {
        return Tenant::query()->where('slug', DemoYardSeeder::SLUG)->first();
    }

    public static function tenantId(): ?int
    {
        $id = Tenant::query()->where('slug', DemoYardSeeder::SLUG)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether /demo is usable: turned on, and the demo yard has been seeded.
     */
    public static function available(): bool
    {
        return self::enabled() && self::tenantId() !== null;
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user?->tenant_id !== null && $user->tenant_id === self::tenantId();
    }

    /**
     * Whether the request or job is running as the demo yard.
     */
    public static function isCurrentTenant(): bool
    {
        $current = CurrentTenant::id();

        return $current !== null && $current === self::tenantId();
    }
}
