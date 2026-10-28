<?php

namespace App\Models;

use App\Accounting\QuickBooks\SendsToQuickBooks;
use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property PaymentTender $tender
 * @property PaymentState $status
 * @property int $amount_cents
 * @property int $refunded_cents everything refunded against it, credit-note refunds to its card included
 * @property int|null $fee_cents the card processor's fee, once known
 * @property CarbonImmutable|null $fee_checked_at when the fee was last looked up
 * @property string|null $reference
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $checkout_url
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $succeeded_at
 * @property-read Order $order
 * @property-read Collection<int, Refund> $refunds
 */
#[Fillable([
    'order_id', 'tender', 'status', 'amount_cents', 'refunded_cents', 'reference',
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
            'refunded_cents' => 'integer',
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

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Whether it was taken on a card through Stripe, so it can be refunded
     * back to that card.
     */
    public function isStripeCard(): bool
    {
        return $this->tender === PaymentTender::StripeCheckout
            && $this->status === PaymentState::Succeeded
            && $this->stripe_payment_intent_id !== null;
    }

    /** What can still be refunded against it. */
    public function refundableCents(): int
    {
        return $this->status === PaymentState::Succeeded ? max(0, $this->amount_cents - $this->refunded_cents) : 0;
    }

    /**
     * What's been refunded off the order from this payment: refunds of the
     * payment itself, not of credit notes paid back onto its card.
     */
    public function orderRefundedCents(): int
    {
        return (int) $this->refunds()->counting()->whereNull('credit_note_id')->sum('amount_cents');
    }

    /** What this payment still counts for on its order. */
    public function netCents(): int
    {
        return $this->status === PaymentState::Succeeded ? max(0, $this->amount_cents - $this->orderRefundedCents()) : 0;
    }
}
