<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Settlement;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property OrderType $type
 * @property OrderStatus $status
 * @property string $source
 * @property int|null $organization_id
 * @property int|null $customer_id
 * @property int|null $organization_location_id
 * @property string|null $fulfillment_status queued | picking | partly_shipped | shipped | delivered
 * @property int|null $delivery_zone_id
 * @property int $delivery_fee_cents
 * @property bool $delivery_fee_manual
 * @property string|null $delivery_km
 * @property int|null $delivery_loads
 * @property string|null $load_weight_kg
 * @property bool $needs_boom
 * @property CarbonImmutable|null $quoted_at
 * @property CarbonImmutable|null $quote_expires_on
 * @property int $revision
 * @property string|null $quote_token
 * @property CarbonImmutable|null $quote_accepted_at
 * @property string|null $quote_accepted_by
 * @property int $location_id
 * @property FulfillmentMethod $fulfillment
 * @property array<string, string|null>|null $delivery_address
 * @property CarbonImmutable|null $requested_on
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
 * @property CarbonImmutable|null $rental_starts_at
 * @property CarbonImmutable|null $rental_ends_at
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $fulfilled_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $created_by
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property-read Organization|null $organization
 * @property-read Customer|null $customer
 * @property-read Location $location
 * @property-read OrganizationLocation|null $organizationLocation
 * @property-read DeliveryZone|null $deliveryZone
 * @property-read Collection<int, Shipment> $shipments
 * @property-read Collection<int, OrderRevision> $revisions
 * @property-read Collection<int, OrderLine> $lines
 */
#[Fillable([
    'number', 'type', 'source', 'organization_id', 'customer_id', 'organization_location_id', 'location_id',
    'fulfillment', 'delivery_address', 'requested_on', 'po_number', 'delivery_zone_id', 'delivery_km', 'needs_boom',
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
        'revision' => 0,
        'delivery_fee_cents' => 0,
        'delivery_fee_manual' => false,
        'needs_boom' => false,
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
            'quoted_at' => 'immutable_datetime',
            'quote_expires_on' => 'immutable_date',
            'quote_accepted_at' => 'immutable_datetime',
            'revision' => 'integer',
            'delivery_fee_cents' => 'integer',
            'delivery_fee_manual' => 'boolean',
            'delivery_km' => 'decimal:1',
            'delivery_loads' => 'integer',
            'load_weight_kg' => 'decimal:1',
            'needs_boom' => 'boolean',
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
     * @return BelongsTo<OrganizationLocation, $this>
     */
    public function organizationLocation(): BelongsTo
    {
        return $this->belongsTo(OrganizationLocation::class);
    }

    /**
     * Delivery runs planned for this order before it's loaded.
     *
     * @return MorphMany<Stop, $this>
     */
    public function stops(): MorphMany
    {
        return $this->morphMany(Stop::class, 'stoppable');
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /**
     * @return HasMany<OrderRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(OrderRevision::class)->orderByDesc('revision');
    }

    /**
     * A draft that's been sent to the customer as a quote.
     */
    public function isQuote(): bool
    {
        return $this->isDraft() && $this->quoted_at !== null;
    }

    public function quoteExpired(): bool
    {
        return $this->isQuote() && $this->quote_expires_on !== null && $this->quote_expires_on->endOfDay()->isPast();
    }

    /**
     * Quoted prices hold until the quote expires.
     */
    public function pricesLocked(): bool
    {
        return $this->isQuote() && ! $this->quoteExpired();
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
