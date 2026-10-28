<?php

namespace App\Models;

use App\Accounting\QuickBooks\SendsToQuickBooks;
use App\Audit\Audited;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money owed back to a customer: returned material (less a restocking
 * fee), returned pallets, or an adjustment. It sits on the account until
 * it's applied to an invoice or refunded.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property string $kind return | pallets | adjustment
 * @property int|null $organization_id
 * @property int|null $customer_id
 * @property int|null $order_id
 * @property int|null $location_id
 * @property-read Location|null $location
 * @property string|null $reason
 * @property int $subtotal_cents
 * @property int $restocking_fee_cents
 * @property int $tax_cents
 * @property string|null $tax_reversal_ref Stripe Tax reversal of the credited part of the sale
 * @property int $deposit_cents
 * @property int $pallet_fee_cents pallet usage fees credited for unused product returned on full pallets, before tax
 * @property int $total_cents
 * @property int $applied_cents
 * @property int $refunded_cents
 * @property string|null $refund_tender
 * @property string $status open | used
 * @property CarbonImmutable $issued_on
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, CreditNoteLine> $lines
 * @property-read Organization|null $organization
 * @property-read Customer|null $customer
 * @property-read Order|null $order
 * @property-read User|null $creator
 */
#[Fillable([
    'number', 'kind', 'organization_id', 'customer_id', 'order_id', 'location_id', 'reason',
    'subtotal_cents', 'restocking_fee_cents', 'tax_cents', 'deposit_cents', 'pallet_fee_cents', 'total_cents',
    'issued_on', 'created_by',
])]
class CreditNote extends Model
{
    use Audited, BelongsToTenant, SendsToQuickBooks;

    /** Changes to these are written to the audit log. */
    public const AUDITED = ['applied_cents', 'refunded_cents'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'applied_cents' => 0,
        'refunded_cents' => 0,
        'subtotal_cents' => 0,
        'restocking_fee_cents' => 0,
        'tax_cents' => 0,
        'deposit_cents' => 0,
        'pallet_fee_cents' => 0,
        'total_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'subtotal_cents' => 'integer',
            'restocking_fee_cents' => 'integer',
            'tax_cents' => 'integer',
            'deposit_cents' => 'integer',
            'pallet_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'applied_cents' => 'integer',
            'refunded_cents' => 'integer',
        ];
    }

    /**
     * @return HasMany<CreditNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
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

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function remainingCents(): int
    {
        return max(0, $this->total_cents - $this->applied_cents - $this->refunded_cents);
    }

    public function syncStatus(): void
    {
        $this->status = $this->remainingCents() > 0 ? 'open' : 'used';
    }
}
