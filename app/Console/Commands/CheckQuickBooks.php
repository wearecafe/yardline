<?php

namespace App\Console\Commands;

use App\Accounting\QuickBooks\QuickBooks;
use App\Accounting\QuickBooks\QuickBooksClient;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Jobs\SendToQuickBooks;
use App\Models\Integration;
use App\Models\QuickBooksSync;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Nightly: send again anything that failed or was waiting, and compare the
 * totals of the last few days' invoices and credit memos with QuickBooks.
 */
class CheckQuickBooks extends Command
{
    protected $signature = 'yardline:quickbooks:check {--days=3}';

    protected $description = 'Re-send failed QuickBooks records and compare recent totals';

    public function handle(): int
    {
        $tenants = Integration::withoutGlobalScopes()
            ->where('provider', IntegrationProvider::QuickBooks->value)
            ->where('status', IntegrationStatus::Connected->value)
            ->pluck('tenant_id');

        foreach (Tenant::query()->whereIn('id', $tenants)->get() as $tenant) {
            CurrentTenant::run($tenant, function () use ($tenant) {
                $integration = QuickBooks::integration();

                if ($integration === null) {
                    return;
                }

                QuickBooksSync::query()->whereIn('status', ['failed', 'waiting'])->whereNull('qbo_id')->get()
                    ->each(fn (QuickBooksSync $s) => SendToQuickBooks::dispatch($s->syncable_type, $s->syncable_id));

                try {
                    $this->compare($integration);
                } catch (Throwable $e) {
                    report($e);
                    $this->error("{$tenant->name}: {$e->getMessage()}");
                }
            });
        }

        return self::SUCCESS;
    }

    private function compare(Integration $integration): void
    {
        $since = now()->subDays((int) $this->option('days'));
        $client = QuickBooksClient::for($integration);

        foreach (['Invoice', 'CreditMemo'] as $type) {
            $ours = QuickBooksSync::query()->where('qbo_type', $type)->where('synced_at', '>=', $since)->whereNotNull('qbo_id')->get()->keyBy('qbo_id');

            foreach ($ours->chunk(50) as $chunk) {
                $ids = $chunk->keys()->map(fn ($id) => "'{$id}'")->implode(',');

                foreach ($client->query("select Id, TotalAmt from {$type} where Id in ({$ids})") as $row) {
                    $sync = $ours->get((string) $row['Id']);
                    $expected = $sync?->meta['total_cents'] ?? null;

                    if ($sync === null || $expected === null || ($sync->meta['voided'] ?? false)) {
                        continue;
                    }

                    $theirs = (int) round((float) $row['TotalAmt'] * 100);

                    if ($theirs !== (int) $expected) {
                        $sync->forceFill([
                            'status' => 'failed',
                            'message' => 'Nightly check: QuickBooks total $'.number_format($theirs / 100, 2).', YardRunner $'.number_format((int) $expected / 100, 2).'.',
                        ])->save();
                    }
                }
            }
        }
    }
}
