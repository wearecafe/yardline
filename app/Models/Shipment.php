<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\ShipmentStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One load of an order: picked together, loaded on a truck or set out for
 * pickup, and delivered with a signature. An order can ship in parts.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property string $number
 * @property FulfillmentMethod $method
 * @property ShipmentStatus $status
 * @property int|null $picker_id
 * @property CarbonImmutable|null $picked_at
 * @property CarbonImmutable|null $loaded_at
 * @property string|null $weight_kg
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $pod_name
 * @property string|null $pod_signature_path
 * @property string|null $pod_photo_path
 * @property string|null $pod_notes
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read User|null $picker
 * @property-read Collection<int, ShipmentLine> $lines
 * @property-read Stop|null $stop
 */
#[Fillable(['order_id', 'number', 'method', 'status', 'picker_id', 'created_by'])]
class Shipment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'method' => FulfillmentMethod::class,
            'status' => ShipmentStatus::class,
            'picked_at' => 'immutable_datetime',
            'loaded_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'weight_kg' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function picker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picker_id');
    }

    /**
     * @return HasMany<ShipmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }

    /**
     * @return MorphOne<Stop, $this>
     */
    public function stop(): MorphOne
    {
        return $this->morphOne(Stop::class, 'stoppable');
    }

    public function isDelivery(): bool
    {
        return $this->method === FulfillmentMethod::Delivery;
    }
}
