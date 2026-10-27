<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quality check at the Picked or Loaded stage: who signed it off (or
 * sent it back, and why), what quantities they confirmed, and any notes
 * or photo.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $shipment_id
 * @property string $stage picked | loaded
 * @property string $outcome signed_off | rejected
 * @property int|null $user_id
 * @property int|null $completed_by
 * @property array<int|string, string|null>|null $quantities shipment line id => quantity confirmed
 * @property string|null $notes
 * @property string|null $photo_path
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 * @property-read User|null $completedBy
 */
#[Fillable(['shipment_id', 'stage', 'outcome', 'user_id', 'completed_by', 'quantities', 'notes', 'photo_path', 'reason'])]
class ShipmentCheck extends Model
{
    use BelongsToTenant;

    public const SIGNED_OFF = 'signed_off';

    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'quantities' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function signedOff(): bool
    {
        return $this->outcome === self::SIGNED_OFF;
    }

    public function stageLabel(): string
    {
        return $this->stage === 'loaded' ? 'Loaded' : 'Picked';
    }
}
