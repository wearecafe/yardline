<?php

namespace App\Accounting\QuickBooks;

use Illuminate\Database\Eloquent\Model;

/**
 * Queues the record for QuickBooks whenever it's saved. The job decides,
 * when it runs, whether there's anything to send.
 *
 * @mixin Model
 */
trait SendsToQuickBooks
{
    public static function bootSendsToQuickBooks(): void
    {
        static::saved(fn (Model $model) => QuickBooks::queue($model));
    }
}
