<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Payments\Fees\RecordCardFees as Record;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

class RecordCardFees extends Command
{
    protected $signature = 'yardrunner:payments:fees';

    /** The name it had before the rename, kept so old habits and docs still work. */
    protected $aliases = ['yardline:payments:fees'];

    protected $description = 'Record what the card processor kept from each card payment, for the profit report';

    public function handle(): int
    {
        foreach (Tenant::query()->get() as $tenant) {
            CurrentTenant::run($tenant, function () use ($tenant) {
                try {
                    $count = app(Record::class)->handle();

                    if ($count > 0) {
                        $this->line("{$tenant->name}: {$count} card fees recorded.");
                    }
                } catch (Throwable $e) {
                    report($e);
                    $this->error("{$tenant->name}: {$e->getMessage()}");
                }
            });
        }

        return self::SUCCESS;
    }
}
