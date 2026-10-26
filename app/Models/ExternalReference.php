<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Maps a Yardline record to its ID in a connected system.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $provider
 * @property string $syncable_type
 * @property int $syncable_id
 * @property string $external_id
 * @property string|null $payload_hash
 * @property CarbonImmutable|null $last_synced_at
 */
#[Fillable(['provider', 'syncable_type', 'syncable_id', 'external_id', 'payload_hash', 'last_synced_at'])]
class ExternalReference extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }
}
