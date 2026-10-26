<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pallets out on deposit: positive when they leave on an order, negative
 * when they come back.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $customer_id
 * @property int|null $order_id
 * @property int|null $credit_note_id
 * @property int|null $location_id
 * @property int $quantity
 * @property int $deposit_cents
 * @property string|null $note
 * @property int|null $user_id
 * @property CarbonImmutable $created_at
 * @property-read Organization|null $organization
 * @property-read Customer|null $customer
 * @property-read Order|null $order
 */
#[Fillable(['organization_id', 'customer_id', 'order_id', 'credit_note_id', 'location_id', 'quantity', 'deposit_cents', 'note', 'user_id'])]
class PalletMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'deposit_cents' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
