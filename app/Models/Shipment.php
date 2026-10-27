<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\ShipmentStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $check_stage picked | loaded while waiting for a sign-off
 * @property int|null $picker_id
 * @property CarbonImmutable|null $picked_at
 * @property int|null $picked_by
 * @property CarbonImmutable|null $loaded_at
 * @property int|null $loaded_by
 * @property CarbonImmutable|null $released_at when stock left the books and the load went to dispatch
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
 * @property-read Collection<int, ShipmentCheck> $checks
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
            'released_at' => 'immutable_datetime',
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

    /**
     * @return HasMany<ShipmentCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(ShipmentCheck::class)->latest('id');
    }

    public function isDelivery(): bool
    {
        return $this->method === FulfillmentMethod::Delivery;
    }

    /**
     * Pick lists still at the yard: not released and not cancelled.
     *
     * @param  Builder<Shipment>  $query
     */
    public function scopeInYard(Builder $query): void
    {
        $query->whereNull('released_at')->whereNotIn('status', [ShipmentStatus::Cancelled->value, ShipmentStatus::Delivered->value, ShipmentStatus::PickedUp->value]);
    }

    /** Stock has left the books and the load can go out or be handed over. */
    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    public function awaitingCheck(): bool
    {
        return $this->check_stage !== null;
    }

    /** Still at the yard: the pick list can be worked or cancelled. */
    public function isInYard(): bool
    {
        return ! $this->isReleased() && ! $this->status->isDone();
    }
}
