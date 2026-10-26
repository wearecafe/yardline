<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * The tenant (supply-yard business) the current request or job belongs to.
 *
 * Stored as hidden Context so it is carried into queued jobs automatically
 * and never written to logs.
 */
final class CurrentTenant
{
    private const KEY = 'tenant_id';

    public static function id(): ?int
    {
        $id = Context::getHidden(self::KEY);

        return $id === null ? null : (int) $id;
    }

    public static function get(): ?Tenant
    {
        $id = self::id();

        if ($id === null) {
            return null;
        }

        return Tenant::find($id);
    }

    public static function set(Tenant|int|null $tenant): void
    {
        $id = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        if ($id === null) {
            Context::forgetHidden(self::KEY);

            return;
        }

        Context::addHidden(self::KEY, (int) $id);
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    /**
     * Run a callback as the given tenant, restoring the previous tenant after.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $previous = self::id();

        self::set($tenant);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }
}
