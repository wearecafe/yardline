<?php

namespace App\Jobs;

use App\Accounting\QuickBooks\AccountMapping;
use App\Accounting\QuickBooks\QuickBooks;
use App\Accounting\QuickBooks\QuickBooksPusher;
use App\Accounting\QuickBooks\TaxCodes;
use App\Enums\UserRole;
use App\Models\QuickBooksSync;
use App\Models\User;
use App\Notifications\QuickBooksSyncFailed;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends one Yardline record to QuickBooks. It reads the record when it
 * runs, so several quick saves become one send. Retried for about a day,
 * then left on the sync log with QuickBooks' error.
 */
class SendToQuickBooks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 7;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600, 1800, 3600, 7200, 21600];

    public function __construct(public string $syncableType, public int $syncableId) {}

    public function uniqueId(): string
    {
        return "quickbooks:{$this->syncableType}:{$this->syncableId}";
    }

    public function handle(): void
    {
        $integration = QuickBooks::integration();
        $class = Relation::getMorphedModel($this->syncableType);
        /** @var Model|null $model */
        $model = $class ? $class::query()->find($this->syncableId) : null;

        if ($integration === null || $model === null) {
            return;
        }

        $pusher = QuickBooksPusher::for($integration);

        if (! AccountMapping::complete($integration) || ! TaxCodes::complete($integration)) {
            if ($this->hasMoney($model)) {
                QuickBooksSync::query()->updateOrCreate(
                    ['syncable_type' => $this->syncableType, 'syncable_id' => $this->syncableId],
                    ['label' => $pusher->label($model), 'status' => 'waiting', 'message' => 'Waiting for accounts and tax codes in Settings → QuickBooks.'],
                );
            }

            return;
        }

        try {
            $pusher->send($model);
        } catch (Throwable $e) {
            QuickBooksSync::query()->updateOrCreate(
                ['syncable_type' => $this->syncableType, 'syncable_id' => $this->syncableId],
                ['label' => $pusher->label($model), 'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 1000)],
            );
            QuickBooksSync::query()->where('syncable_type', $this->syncableType)->where('syncable_id', $this->syncableId)->increment('attempts');

            throw $e;
        }
    }

    /**
     * Out of retries: tell the owners, at most once a day.
     */
    public function failed(?Throwable $e): void
    {
        $integration = QuickBooks::integration();

        if ($integration === null) {
            return;
        }

        $last = $integration->settings['failure_notice_at'] ?? null;

        if (is_string($last) && now()->subDay()->lessThan($last)) {
            return;
        }

        $integration->forceFill(['settings' => [...($integration->settings ?? []), 'failure_notice_at' => now()->toIso8601String()]])->save();

        Notification::send(
            User::query()->where('tenant_id', $integration->tenant_id)->where('role', UserRole::Owner->value)->get(),
            new QuickBooksSyncFailed($e?->getMessage() ?? 'Unknown error'),
        );
    }

    private function hasMoney(Model $model): bool
    {
        return ! in_array($this->syncableType, ['organization', 'product', 'order'], true);
    }
}
