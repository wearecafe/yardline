<?php

namespace App\Models;

use App\Accounting\QuickBooks\SendsToQuickBooks;
use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid back out. Against an order payment it lowers what the order
 * has been paid; against a credit note (credit_note_id set) it spends the
 * credit and only uses the payment's card to send the money back.
 *
 * A pending refund already counts: the money is on its way back, and
 * counting it stops the same amount being refunded twice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $order_id
 * @property int|null $payment_id
 * @property int|null $credit_note_id
 * @property PaymentTender $tender
 * @property PaymentState $status
 * @property string $source yardrunner | stripe (refunded in Stripe, recorded from its webhook)
 * @property int $amount_cents
 * @property int $fee_returned_cents YardRunner's application fee given back to the yard with a card refund
 * @property string|null $reason
 * @property string|null $stripe_refund_id
 * @property string|null $failure_message
 * @property int|null $refunded_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable $created_at
 * @property-read Order|null $order
 * @property-read Payment|null $payment
 * @property-read CreditNote|null $creditNote
 * @property-read User|null $refunder
 */
#[Fillable([
    'order_id', 'payment_id', 'credit_note_id', 'tender', 'status', 'source', 'amount_cents', 'fee_returned_cents',
    'reason', 'stripe_refund_id', 'failure_message', 'refunded_by', 'approved_by', 'succeeded_at',
])]
class Refund extends Model
{
    use BelongsToTenant, SendsToQuickBooks;

    public const SOURCE_YARDRUNNER = 'yardrunner';

    public const SOURCE_STRIPE = 'stripe';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'source' => self::SOURCE_YARDRUNNER,
    ];

    protected function casts(): array
    {
        return [
            'tender' => PaymentTender::class,
            'status' => PaymentState::class,
            'amount_cents' => 'integer',
            'fee_returned_cents' => 'integer',
            'succeeded_at' => 'datetime',
        ];
    }

    /**
     * Refunds that count: succeeded, or on their way.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCounting(Builder $query): void
    {
        $query->whereIn('status', [PaymentState::Pending->value, PaymentState::Succeeded->value]);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<CreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function isToCard(): bool
    {
        return $this->tender === PaymentTender::StripeCheckout;
    }
}
