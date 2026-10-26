<?php

namespace App\Models;

use App\Accounting\QuickBooks\SendsToQuickBooks;
use App\Enums\PaymentTerms;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The bill for a goods order. Amounts and payments live on the order; the
 * invoice adds a number, the terms and the due date.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property int $order_id
 * @property int|null $organization_id
 * @property int|null $customer_id
 * @property PaymentTerms $terms
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $due_on
 * @property string $status open | paid | void
 * @property CarbonImmutable|null $sent_at
 * @property-read Order $order
 * @property-read Organization|null $organization
 * @property-read Customer|null $customer
 */
#[Fillable(['number', 'order_id', 'organization_id', 'customer_id', 'terms', 'issued_on', 'due_on', 'status'])]
class Invoice extends Model
{
    use BelongsToTenant, SendsToQuickBooks;

    protected function casts(): array
    {
        return [
            'terms' => PaymentTerms::class,
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
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

    public function balanceCents(): int
    {
        return $this->status === 'void' ? 0 : $this->order->balanceCents();
    }

    public function daysOverdue(?CarbonImmutable $on = null): int
    {
        $on ??= CarbonImmutable::today();

        return $this->due_on->lessThan($on) ? (int) $this->due_on->diffInDays($on) : 0;
    }
}
