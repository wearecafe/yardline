<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a quote said when it was sent, so later revisions can be compared.
 *
 * @property int $id
 * @property int $order_id
 * @property int $revision
 * @property array<string, mixed> $snapshot
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read User|null $creator
 */
#[Fillable(['order_id', 'revision', 'snapshot', 'created_by'])]
class OrderRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
