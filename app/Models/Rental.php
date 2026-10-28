<?php

namespace App\Models;

use App\Access\HasYard;
use App\Accounting\QuickBooks\SendsToQuickBooks;
use App\Enums\DepositStatus;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentState;
use App\Enums\RentalPaymentKind;
use App\Enums\RentalPaymentMethod;
use App\Enums\RentalStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\RentalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A rental contract. Rentals are always prepaid with a deposit and are
 * invoiced separately from goods.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property RentalStatus $status
 * @property string $source
 * @property int|null $organization_id
 * @property int|null $customer_id
 * @property int $location_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $checked_out_at
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $cancelled_at
 * @property FulfillmentMethod $fulfillment
 * @property array<string, string|null>|null $delivery_address
 * @property int|null $delivery_zone_id
 * @property bool $damage_waiver
 * @property string $damage_waiver_percent
 * @property bool $bill_fuel_cleaning
 * @property bool $text_reminders
 * @property int $rental_cents
 * @property int $waiver_cents
 * @property int $delivery_fee_cents
 * @property int $charges_cents
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property int $amount_paid_cents
 * @property string|null $tax_calculation_ref
 * @property string|null $tax_transaction_ref
 * @property int $deposit_cents
 * @property DepositStatus $deposit_status
 * @property int $deposit_captured_cents
 * @property RentalPaymentMethod $payment_method
 * @property string|null $signing_token
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Organization|null $organization
 * @property-read Customer|null $customer
 * @property-read Location $location
 * @property-read DeliveryZone|null $deliveryZone
 * @property-read Collection<int, RentalItem> $items
 * @property-read RentalAgreement|null $agreement
 */
#[Fillable([
    'number', 'status', 'source', 'organization_id', 'customer_id', 'location_id',
    'starts_at', 'ends_at', 'fulfillment', 'delivery_address', 'delivery_zone_id',
    'damage_waiver', 'damage_waiver_percent', 'bill_fuel_cleaning', 'text_reminders',
    'deposit_cents', 'payment_method', 'notes', 'created_by',
])]
#[Hidden(['signing_token'])]
class Rental extends Model implements HasYard
{
    /** @use HasFactory<RentalFactory> */
    use BelongsToTenant, HasFactory, SendsToQuickBooks;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'quote',
        'source' => 'counter',
        'fulfillment' => 'pickup',
        'damage_waiver' => true,
        'damage_waiver_percent' => 0,
        'bill_fuel_cleaning' => true,
        'text_reminders' => true,
        'deposit_status' => 'none',
        'payment_method' => 'card_on_file',
        'rental_cents' => 0,
        'waiver_cents' => 0,
        'delivery_fee_cents' => 0,
        'charges_cents' => 0,
        'subtotal_cents' => 0,
        'tax_cents' => 0,
        'total_cents' => 0,
        'amount_paid_cents' => 0,
        'deposit_cents' => 0,
        'deposit_captured_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => RentalStatus::class,
            'fulfillment' => FulfillmentMethod::class,
            'deposit_status' => DepositStatus::class,
            'payment_method' => RentalPaymentMethod::class,
            'delivery_address' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'damage_waiver' => 'boolean',
            'damage_waiver_percent' => 'decimal:2',
            'bill_fuel_cleaning' => 'boolean',
            'text_reminders' => 'boolean',
            'rental_cents' => 'integer',
            'waiver_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'charges_cents' => 'integer',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'deposit_cents' => 'integer',
            'deposit_captured_cents' => 'integer',
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
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /**
     * @return HasMany<RentalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RentalItem::class);
    }

    /**
     * @return HasOne<RentalAgreement, $this>
     */
    public function agreement(): HasOne
    {
        return $this->hasOne(RentalAgreement::class)->latestOfMany();
    }

    /**
     * @return HasMany<RentalInspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(RentalInspection::class);
    }

    /**
     * @return HasMany<RentalCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(RentalCharge::class);
    }

    /**
     * @return HasMany<RentalPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(RentalPayment::class);
    }

    /**
     * @return HasMany<RentalNotification, $this>
     */
    public function notificationsSent(): HasMany
    {
        return $this->hasMany(RentalNotification::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [RentalStatus::Quote, RentalStatus::Reserved], true);
    }

    public function isOverdue(): bool
    {
        return $this->status === RentalStatus::Out && $this->ends_at->isPast();
    }

    public function isSigned(): bool
    {
        return $this->agreement?->signed_at !== null;
    }

    /**
     * What cancelling would refund: card and other charges that went
     * through. A deposit hold is released, not refunded.
     */
    public function refundableCents(): int
    {
        return (int) $this->payments()
            ->where('kind', RentalPaymentKind::Charge->value)
            ->where('status', PaymentState::Succeeded->value)
            ->sum('amount_cents');
    }

    public function balanceCents(): int
    {
        return max(0, $this->total_cents - $this->amount_paid_cents);
    }

    /**
     * Status as shown to people, including the derived "overdue".
     */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status->value;
    }

    public function customerName(): string
    {
        return $this->organization->name ?? $this->customer->full_name ?? 'Walk-in';
    }

    /**
     * Rentals that hold their units over the given time range.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->whereIn('status', RentalStatus::booking())
            ->where('starts_at', '<', $to)
            ->where(function (Builder $query) use ($from) {
                // An "out" rental past its end still holds the unit until it's back.
                $query->where('ends_at', '>', $from)
                    ->orWhere('status', RentalStatus::Out->value);
            });
    }

    /** @return list<int> */
    public function yardIds(): array
    {
        return [(int) $this->location_id];
    }
}
