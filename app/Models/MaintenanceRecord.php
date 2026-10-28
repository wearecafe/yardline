<?php

namespace App\Models;

use App\Access\HasYard;
use App\Enums\MaintenanceKind;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_unit_id
 * @property MaintenanceKind $kind
 * @property string $status
 * @property string $description
 * @property string|null $hour_meter
 * @property int $cost_cents
 * @property int|null $rental_id
 * @property int|null $user_id
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $completed_at
 * @property-read RentalUnit $unit
 */
#[Fillable([
    'rental_unit_id', 'kind', 'status', 'description', 'hour_meter', 'cost_cents',
    'rental_id', 'user_id', 'opened_at', 'completed_at',
])]
class MaintenanceRecord extends Model implements HasYard
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'cost_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => MaintenanceKind::class,
            'hour_meter' => 'decimal:1',
            'cost_cents' => 'integer',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RentalUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class, 'rental_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** @return list<int> */
    public function yardIds(): array
    {
        return [(int) $this->unit->location_id];
    }
}
