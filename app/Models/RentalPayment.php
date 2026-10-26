<?php

namespace App\Models;

use App\Enums\PaymentState;
use App\Enums\RentalPaymentKind;
use App\Enums\RentalTender;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money movements on a rental: charges, deposit holds and what happens to
 * them, refunds.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_id
 * @property RentalPaymentKind $kind
 * @property RentalTender $tender
 * @property PaymentState $status
 * @property int $amount_cents
 * @property string|null $reference
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_checkout_session_id
 * @property string|null $checkout_url
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable $created_at
 * @property-read Rental $rental
 */
#[Fillable([
    'rental_id', 'kind', 'tender', 'status', 'amount_cents', 'reference',
    'stripe_payment_intent_id', 'stripe_checkout_session_id', 'checkout_url',
    'recorded_by', 'succeeded_at',
])]
class RentalPayment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => RentalPaymentKind::class,
            'tender' => RentalTender::class,
            'status' => PaymentState::class,
            'amount_cents' => 'integer',
            'succeeded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
