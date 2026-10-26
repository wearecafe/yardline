<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Support\Facades\Cache;

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

    /**
     * The demo yard, building it first if it's turned on but hasn't been
     * seeded yet (a fresh deploy before the nightly rebuild has run).
     */
    public static function ensureTenant(): ?Tenant
    {
        if (! self::enabled()) {
            return null;
        }

        $tenant = self::tenant();

        if ($tenant !== null) {
            return $tenant;
        }

        Cache::lock('yardline:demo:seed', 120)->block(60, function () {
            if (self::tenantId() === null) {
                $previous = CurrentTenant::get();
                CurrentTenant::set(null);

                try {
                    app(DemoYardSeeder::class)->run();
                } finally {
                    CurrentTenant::set($previous);
                }
            }
        });

        return self::tenant();
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
