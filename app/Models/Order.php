<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Settlement;
use App\Tenancy\BelongsToTenant;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property OrderType $type
 * @property OrderStatus $status
 * @property string $source
 * @property int|null $organization_id
 * @property int|null $customer_id
 * @property int $location_id
 * @property FulfillmentMethod $fulfillment
 * @property array<string, string|null>|null $delivery_address
 * @property Carbon|null $requested_on
 * @property string|null $po_number
 * @property Settlement|null $settlement
 * @property PaymentStatus $payment_status
 * @property int|null $credit_override_by
 * @property string|null $credit_override_reason
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property int $deposit_cents
 * @property int $amount_paid_cents
 * @property string|null $tax_calculation_ref
 * @property string|null $tax_transaction_ref
 * @property Carbon|null $rental_starts_at
 * @property Carbon|null $rental_ends_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 * @property string|null $notes
 * @property Carbon $created_at
 * @property-read Organization|null $organization
 * @property-read Customer|null $customer
 * @property-read Location $location
 * @property-read Collection<int, OrderLine> $lines
 */
#[Fillable([
    'number', 'type', 'source', 'organization_id', 'customer_id', 'location_id',
    'fulfillment', 'delivery_address', 'requested_on', 'po_number',
    'rental_starts_at', 'rental_ends_at', 'notes', 'created_by',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'sale',
        'status' => 'draft',
        'source' => 'yardline',
        'fulfillment' => 'pickup',
        'payment_status' => 'unpaid',
        'subtotal_cents' => 0,
        'tax_cents' => 0,
        'total_cents' => 0,
        'deposit_cents' => 0,
        'amount_paid_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'fulfillment' => FulfillmentMethod::class,
            'settlement' => Settlement::class,
            'payment_status' => PaymentStatus::class,
            'delivery_address' => 'array',
            'requested_on' => 'date',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'deposit_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'rental_starts_at' => 'datetime',
            'rental_ends_at' => 'datetime',
            'returned_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creditOverrideUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credit_override_by');
    }

    public function isRental(): bool
    {
        return $this->type === OrderType::Rental;
    }

    public function isDraft(): bool
    {
        return $this->status === OrderStatus::Draft;
    }

    /**
     * Everything the customer owes on this order, including any rental deposit.
     */
    public function amountDueCents(): int
    {
        return $this->total_cents + $this->deposit_cents;
    }

    public function balanceCents(): int
    {
        return max(0, $this->amountDueCents() - $this->amount_paid_cents);
    }

    /**
     * Orders that are using up an organization's credit.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpenOnAccount(Builder $query): void
    {
        $query->where('settlement', Settlement::Account->value)
            ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Fulfilled->value])
            ->where('payment_status', '!=', PaymentStatus::Paid->value);
    }
}
