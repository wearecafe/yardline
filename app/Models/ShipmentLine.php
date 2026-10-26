<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shipment_id
 * @property int $order_line_id
 * @property string $quantity to pick, in the order line's unit
 * @property string|null $picked_quantity actually loaded
 * @property bool $short
 * @property string|null $note
 * @property CarbonImmutable|null $picked_at
 * @property-read Shipment $shipment
 * @property-read OrderLine $orderLine
 */
#[Fillable(['shipment_id', 'order_line_id', 'quantity', 'picked_quantity', 'short', 'note', 'picked_at'])]
class ShipmentLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'picked_quantity' => 'decimal:3',
            'short' => 'boolean',
            'picked_at' => 'immutable_datetime',
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
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * What goes on the truck: the picked quantity once confirmed.
     */
    public function loadedQuantity(): float
    {
        return (float) ($this->picked_quantity ?? $this->quantity);
    }
}
