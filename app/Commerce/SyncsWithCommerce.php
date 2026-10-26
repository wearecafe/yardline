<?php

namespace App\Commerce;

use App\Enums\IntegrationProvider;
use App\Jobs\PushToCommerce;
use App\Models\Integration;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Pushes the model to connected storefronts after it is saved.
 *
 * @mixin Model
 */
trait SyncsWithCommerce
{
    private static bool $commerceSyncPaused = false;

    public static function bootSyncsWithCommerce(): void
    {
        static::saved(function (Model $model): void {
            if (self::$commerceSyncPaused) {
                return;
            }

            foreach (IntegrationProvider::commerce() as $provider) {
                if (Integration::activeFor($provider) !== null) {
                    PushToCommerce::dispatch($provider, $model->getMorphClass(), (int) $model->getKey())
                        ->afterCommit();
                }
            }
        });
    }

    /**
     * Save without echoing the change back out, e.g. when applying an
     * inbound update from the storefront itself.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutCommerceSync(Closure $callback): mixed
    {
        self::$commerceSyncPaused = true;

        try {
            return $callback();
        } finally {
            self::$commerceSyncPaused = false;
        }
    }
}
