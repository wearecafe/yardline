<?php

namespace App\Payments\Fees;

use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Enums\RentalPaymentKind;
use App\Enums\RentalTender;
use App\Models\Payment;
use App\Models\RentalPayment;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Fills in the card fee on the current business's card payments, goods and
 * rentals. Each payment is looked up until its fee is known, at most once
 * an hour and for 30 days after it was taken. Cash, cheque, e-Transfer and
 * credit notes have no fee.
 */
class RecordCardFees
{
    public function __construct(private readonly CardFees $fees) {}

    /**
     * @return int payments whose fee was recorded
     */
    public function handle(): int
    {
        $recorded = 0;

        $payments = $this->pending(Payment::query())
            ->whereIn('tender', [PaymentTender::StripeCheckout->value, PaymentTender::CardTerminal->value])
            ->get();

        $rentalPayments = $this->pending(RentalPayment::query())
            ->whereIn('tender', [RentalTender::CardOnFile->value, RentalTender::PaymentLink->value, RentalTender::CardTerminal->value])
            ->whereIn('kind', [RentalPaymentKind::Charge->value, RentalPaymentKind::DepositCapture->value])
            ->get();

        foreach ([...$payments, ...$rentalPayments] as $payment) {
            $recorded += $this->record($payment) ? 1 : 0;
        }

        return $recorded;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function pending(Builder $query): Builder
    {
        return $query
            ->where('status', PaymentState::Succeeded->value)
            ->whereNull('fee_cents')
            ->where('succeeded_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->whereNull('fee_checked_at')->orWhere('fee_checked_at', '<', now()->subHour()))
            ->orderBy('id')
            ->limit(500);
    }

    private function record(Payment|RentalPayment $payment): bool
    {
        try {
            $fee = $this->fees->feeFor($payment);
        } catch (Throwable $e) {
            report($e);
            $fee = null;
        }

        $payment->forceFill(['fee_cents' => $fee, 'fee_checked_at' => now()])->saveQuietly();

        return $fee !== null;
    }
}
