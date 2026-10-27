<?php

namespace App\Models;

use App\Accounting\QuickBooks\SendsToQuickBooks;
use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property PaymentTender $tender
 * @property PaymentState $status
 * @property int $amount_cents
 * @property int|null $fee_cents the card processor's fee, once known
 * @property CarbonImmutable|null $fee_checked_at when the fee was last looked up
 * @property string|null $reference
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $checkout_url
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $succeeded_at
 * @property-read Order $order
 */
#[Fillable([
    'order_id', 'tender', 'status', 'amount_cents', 'reference',
    'stripe_checkout_session_id', 'stripe_payment_intent_id', 'checkout_url',
    'recorded_by', 'succeeded_at', 'fee_cents', 'fee_checked_at',
])]
class Payment extends Model
{
    use BelongsToTenant, SendsToQuickBooks;

    protected function casts(): array
    {
        return [
            'tender' => PaymentTender::class,
            'status' => PaymentState::class,
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'succeeded_at' => 'datetime',
            'fee_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
