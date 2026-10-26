<?php

namespace App\Jobs;

use App\Commerce\ConnectorFactory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Customer;
use App\Models\ExternalReference;
use App\Models\Integration;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pushes one Yardline record to a connected storefront.
 *
 * The tenant travels with the job as hidden Context, so tenant-scoped
 * queries work here exactly as they did in the request.
 */
class PushToCommerce implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public int $uniqueFor = 60;

    public function __construct(
        public IntegrationProvider $provider,
        public string $syncableType,
        public int $syncableId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->provider->value}:{$this->syncableType}:{$this->syncableId}";
    }

    public function handle(ConnectorFactory $connectors): void
    {
        $integration = Integration::activeFor($this->provider);
        $class = Relation::getMorphedModel($this->syncableType);
        $model = $class ? $class::query()->find($this->syncableId) : null;

        if ($integration === null || $model === null) {
            return;
        }

        $reference = ExternalReference::query()
            ->where('provider', $this->provider->value)
            ->where('syncable_type', $this->syncableType)
            ->where('syncable_id', $this->syncableId)
            ->first();

        $connector = $connectors->for($integration);

        try {
            $externalId = match (true) {
                $model instanceof Product => $connector->pushProduct($model, $reference),
                $model instanceof Customer => $connector->pushCustomer($model, $reference),
                default => null,
            };
        } catch (Throwable $e) {
            $integration->update(['last_error' => $e->getMessage()]);

            throw $e;
        }

        if ($externalId !== null) {
            ExternalReference::query()->updateOrCreate(
                [
                    'provider' => $this->provider->value,
                    'syncable_type' => $this->syncableType,
                    'syncable_id' => $this->syncableId,
                ],
                ['external_id' => $externalId, 'last_synced_at' => now()],
            );
        }

        $integration->update([
            'last_synced_at' => now(),
            'last_error' => null,
            'status' => IntegrationStatus::Connected,
        ]);
    }
}
