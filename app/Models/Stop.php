<?php

namespace App\Models;

use App\Enums\StopStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A truck run on the dispatch board: a material delivery, or a rental
 * drop-off or pickup.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $stoppable_type
 * @property int $stoppable_id
 * @property string $kind delivery | rental_drop | rental_pickup
 * @property int|null $truck_id
 * @property CarbonImmutable|null $date
 * @property string|null $starts_at HH:MM:SS
 * @property int $duration_minutes
 * @property StopStatus $status
 * @property CarbonImmutable|null $eta_at
 * @property CarbonImmutable|null $eta_sent_at
 * @property CarbonImmutable|null $completed_at
 * @property array<string, string|null>|null $address
 * @property string|null $needs
 * @property-read Truck|null $truck
 * @property-read Shipment|Rental|Order $stoppable
 */
#[Fillable(['stoppable_type', 'stoppable_id', 'kind', 'truck_id', 'date', 'starts_at', 'duration_minutes', 'status', 'eta_at', 'address', 'needs'])]
class Stop extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'unscheduled',
        'duration_minutes' => 60,
    ];

    protected function casts(): array
    {
        return [
            'status' => StopStatus::class,
            'date' => 'immutable_date',
            'eta_at' => 'immutable_datetime',
            'eta_sent_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'address' => 'array',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Truck, $this>
     */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function stoppable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isRental(): bool
    {
        return $this->kind !== 'delivery';
    }

    public function addressLine(): string
    {
        return implode(', ', array_filter([$this->address['line1'] ?? null, $this->address['city'] ?? null]));
    }
}
