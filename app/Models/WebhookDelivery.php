<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * One webhook Yardline received from Stripe, and what became of
 * it. Feeds integration health in the back office. Not tenant-scoped: a
 * delivery may not match any tenant (bad signature, unknown shop).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $provider
 * @property string|null $topic
 * @property string $outcome
 * @property string|null $detail
 * @property CarbonImmutable $received_at
 * @property-read Tenant|null $tenant
 */
#[Fillable(['tenant_id', 'provider', 'topic', 'outcome', 'detail', 'received_at'])]
class WebhookDelivery extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 30;

    /** Handed to Yardline's own code to process. */
    public const ACCEPTED = 'accepted';

    /** Valid, but nothing for Yardline to do (an unknown shop, another event). */
    public const IGNORED = 'ignored';

    /** Signature check failed. */
    public const REJECTED = 'rejected';

    /** Yardline failed while handling it. */
    public const FAILED = 'failed';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /**
     * Log a delivery. Never lets logging break the webhook itself.
     */
    public static function record(string $provider, ?string $topic, string $outcome, ?int $tenantId = null, ?string $detail = null): void
    {
        try {
            static::query()->create([
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'topic' => $topic === null || $topic === '' ? null : mb_substr($topic, 0, 80),
                'outcome' => $outcome,
                'detail' => $detail === null ? null : mb_substr($detail, 0, 255),
                'received_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('received_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
