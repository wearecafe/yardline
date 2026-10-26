<?php

namespace App\Accounting\QuickBooks;

use App\Enums\IntegrationProvider;
use App\Jobs\SendToQuickBooks;
use App\Models\Integration;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Entry point for sending records to QuickBooks.
 */
final class QuickBooks
{
    private static bool $muted = false;

    /** @var array<int, array{0: Integration|false, 1: int}> per tenant: the integration and when it was looked up */
    private static array $active = [];

    public static function queue(Model $model): void
    {
        if (self::$muted || CurrentTenant::id() === null || self::integration() === null) {
            return;
        }

        SendToQuickBooks::dispatch($model->getMorphClass(), (int) $model->getKey())->afterCommit();
    }

    public static function integration(): ?Integration
    {
        $tenant = CurrentTenant::id();

        if ($tenant === null || Demo::isCurrentTenant()) {
            return null;
        }

        // Looked up at most once a minute; saving the integration clears it.
        if (! isset(self::$active[$tenant]) || self::$active[$tenant][1] < time() - 60) {
            self::$active[$tenant] = [Integration::activeFor(IntegrationProvider::QuickBooks) ?? false, time()];
        }

        return self::$active[$tenant][0] ?: null;
    }

    public static function forget(): void
    {
        self::$active = [];
    }

    /**
     * Runs without queueing anything, e.g. while recording a payment that
     * came from QuickBooks in the first place.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function muted(Closure $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }
}
