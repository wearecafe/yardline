<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where one Yardline record stands with QuickBooks.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $syncable_type
 * @property int $syncable_id
 * @property string|null $label
 * @property string $status synced | waiting | skipped | failed
 * @property string|null $qbo_type
 * @property string|null $qbo_id
 * @property string|null $message
 * @property int $attempts
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['syncable_type', 'syncable_id', 'label', 'status', 'qbo_type', 'qbo_id', 'message', 'attempts', 'meta', 'synced_at'])]
class QuickBooksSync extends Model
{
    use BelongsToTenant;

    protected $table = 'quickbooks_syncs';

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function for(Model $model): ?self
    {
        return self::query()->where('syncable_type', $model->getMorphClass())->where('syncable_id', $model->getKey())->first();
    }
}
