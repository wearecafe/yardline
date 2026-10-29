<?php

namespace App\Rentals\Payments;

use App\Enums\DepositStatus;
use App\Enums\PaymentState;
use App\Enums\RentalPaymentKind;
use App\Enums\RentalTender;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\RentalPayment;
use App\Models\User;
use App\Rentals\RentalRules;
use App\Support\StripeGateway;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every money movement on a rental goes through here, so the rental's paid
 * amount and deposit status always match its payment rows.
 */
class RentalPayments
{
    public function __construct(private readonly RentalGateway $gateway) {}

    /**
     * Charge the balance to the customer's saved card.
     */
    public function chargeCardOnFile(Rental $rental, ?User $user, ?int $cents = null): RentalPayment
    {
        $cents ??= $rental->balanceCents();
        $customer = $this->customer($rental);

        if ($cents <= 0) {
            RentalRules::fail('Nothing is owing on this rental.');
        }

        try {
            $reference = $this->gateway->charge($customer, $cents, "Rental {$rental->number}", $this->metadata($rental));
        } catch (CardDeclined $e) {
            RentalRules::fail($e->getMessage(), 'payment');
        }

        return $this->recordSucceeded($rental, RentalPaymentKind::Charge, RentalTender::CardOnFile, $cents, $user, stripeIntent: $reference);
    }

    /**
     * Payment taken at the counter: terminal, cash, cheque, e-Transfer.
     */
    public function recordCounterPayment(Rental $rental, RentalTender $tender, int $cents, ?User $user, ?string $reference = null): RentalPayment
    {
        if ($cents <= 0) {
            RentalRules::fail('Enter an amount above zero.', 'amount');
        }

        return $this->recordSucceeded($rental, RentalPaymentKind::Charge, $tender, $cents, $user, $reference);
    }

    /**
     * Hosted payment page for the balance (it also saves the card).
     */
    public function paymentLink(Rental $rental, ?User $user, string $returnUrl): RentalPayment
    {
        $cents = $rental->balanceCents();

        if ($cents <= 0) {
            RentalRules::fail('Nothing is owing on this rental.');
        }

        $rental->payments()
            ->where('tender', RentalTender::PaymentLink->value)
            ->where('status', PaymentState::Pending->value)
            ->update(['status' => PaymentState::Failed->value]);

        $payment = $rental->payments()->create([
            'kind' => RentalPaymentKind::Charge,
            'tender' => RentalTender::PaymentLink,
            'status' => PaymentState::Pending,
            'amount_cents' => $cents,
            'recorded_by' => $user?->id,
        ]);
        $payment->setRelation('rental', $rental);

        try {
            $link = $this->gateway->paymentLink($payment, "Rental {$rental->number}", $returnUrl);
        } catch (Throwable $e) {
            $payment->update(['status' => PaymentState::Failed]);
            report($e);
            RentalRules::fail('Could not create a payment link: '.$e->getMessage(), 'payment');
        }

        $payment->update(['stripe_checkout_session_id' => $link->sessionId, 'checkout_url' => $link->url]);

        return $payment;
    }

    /**
     * A payment link was paid (Stripe webhook or the fake checkout).
     *
     * @param  array{payment_method?: string|null, brand?: string|null, last4?: string|null}  $card
     */
    public function completeLink(RentalPayment $payment, ?string $intent, array $card = []): void
    {
        if ($payment->status === PaymentState::Succeeded) {
            return;
        }

        DB::transaction(function () use ($payment, $intent, $card) {
            $payment->update([
                'status' => PaymentState::Succeeded,
                'stripe_payment_intent_id' => $intent,
                'succeeded_at' => now(),
            ]);

            $customer = $payment->rental->customer;
            if ($customer !== null && filled($card['last4'] ?? null)) {
                $customer->forceFill([
                    'stripe_payment_method_id' => $card['payment_method'] ?? $customer->stripe_payment_method_id,
                    'card_brand' => $card['brand'] ?? null,
                    'card_last4' => $card['last4'],
                ])->save();
            }

            $this->refresh($payment->rental);
        });
    }

    /**
     * Put the deposit on hold against the saved card. Cash deposits are
     * recorded as taken and refunded on return.
     */
    public function holdDeposit(Rental $rental, ?User $user, ?RentalTender $tender = null): ?RentalPayment
    {
        if ($rental->deposit_cents <= 0 || $rental->deposit_status === DepositStatus::Held) {
            return null;
        }

        $tender ??= RentalTender::CardOnFile;
        $reference = null;

        if ($tender === RentalTender::CardOnFile) {
            try {
                $reference = $this->gateway->hold($this->customer($rental), $rental->deposit_cents, "Deposit, rental {$rental->number}", $this->metadata($rental));
            } catch (CardDeclined $e) {
                RentalRules::fail($e->getMessage(), 'deposit');
            }
        }

        $payment = $this->recordSucceeded($rental, RentalPaymentKind::DepositHold, $tender, $rental->deposit_cents, $user, stripeIntent: $reference);

        $rental->forceFill(['deposit_status' => DepositStatus::Held])->save();

        return $payment;
    }

    /**
     * Settle the deposit on return: capture what's needed to cover what's
     * owing, release the rest.
     */
    public function settleDeposit(Rental $rental, ?User $user): void
    {
        if ($rental->deposit_status !== DepositStatus::Held) {
            return;
        }

        $hold = $rental->payments()
            ->where('kind', RentalPaymentKind::DepositHold->value)
            ->where('status', PaymentState::Succeeded->value)
            ->latest('id')
            ->first();

        if ($hold === null) {
            $rental->forceFill(['deposit_status' => DepositStatus::Released])->save();

            return;
        }

        $owing = $rental->balanceCents();
        $capture = min($owing, $hold->amount_cents);

        DB::transaction(function () use ($rental, $hold, $capture, $user) {
            if ($capture > 0) {
                if ($hold->stripe_payment_intent_id !== null) {
                    $this->gateway->capture($hold->stripe_payment_intent_id, $capture);
                }

                $this->recordSucceeded($rental, RentalPaymentKind::DepositCapture, $hold->tender, $capture, $user, stripeIntent: $hold->stripe_payment_intent_id);
            } elseif ($hold->stripe_payment_intent_id !== null) {
                $this->gateway->release($hold->stripe_payment_intent_id);
            }

            $released = $hold->amount_cents - $capture;
            if ($released > 0) {
                $this->recordSucceeded($rental, RentalPaymentKind::DepositRelease, $hold->tender, $released, $user, stripeIntent: $hold->stripe_payment_intent_id);
            }

            $rental->forceFill([
                'deposit_status' => $capture > 0 ? DepositStatus::Captured : DepositStatus::Released,
                'deposit_captured_cents' => $capture,
            ])->save();
        });
    }

    /**
     * Refund what's been paid (cancellations).
     */
    public function refundAll(Rental $rental, ?User $user): void
    {
        $charges = $rental->payments()
            ->where('kind', RentalPaymentKind::Charge->value)
            ->where('status', PaymentState::Succeeded->value)
            ->get();

        foreach ($charges as $charge) {
            if ($charge->stripe_payment_intent_id !== null) {
                $this->gateway->refund($charge->stripe_payment_intent_id, $charge->amount_cents);
            }

            $refund = $this->recordSucceeded($rental, RentalPaymentKind::Refund, $charge->tender, $charge->amount_cents, $user, stripeIntent: $charge->stripe_payment_intent_id);

            // YardRunner's fee came back to the yard with the card refund.
            if ($charge->stripe_payment_intent_id !== null) {
                $refund->forceFill(['fee_returned_cents' => StripeGateway::applicationFeeReturned($charge->amount_cents, $charge->amount_cents)])->saveQuietly();
            }
        }

        if ($rental->deposit_status === DepositStatus::Held) {
            $hold = $rental->payments()->where('kind', RentalPaymentKind::DepositHold->value)->latest('id')->first();

            if ($hold?->stripe_payment_intent_id !== null) {
                $this->gateway->release($hold->stripe_payment_intent_id);
            }

            $rental->forceFill(['deposit_status' => DepositStatus::Released])->save();
        }
    }

    /**
     * Re-total paid amounts: charges and captured deposits, less refunds.
     */
    public function refresh(Rental $rental): Rental
    {
        $sum = fn (RentalPaymentKind $kind) => (int) $rental->payments()
            ->where('kind', $kind->value)
            ->where('status', PaymentState::Succeeded->value)
            ->sum('amount_cents');

        $rental->amount_paid_cents = max(0, $sum(RentalPaymentKind::Charge) + $sum(RentalPaymentKind::DepositCapture) - $sum(RentalPaymentKind::Refund));
        $rental->save();

        return $rental;
    }

    private function recordSucceeded(Rental $rental, RentalPaymentKind $kind, RentalTender $tender, int $cents, ?User $user, ?string $reference = null, ?string $stripeIntent = null): RentalPayment
    {
        $payment = $rental->payments()->create([
            'kind' => $kind,
            'tender' => $tender,
            'status' => PaymentState::Succeeded,
            'amount_cents' => $cents,
            'reference' => $reference,
            'stripe_payment_intent_id' => $stripeIntent,
            'recorded_by' => $user?->id,
            'succeeded_at' => now(),
        ]);

        $this->refresh($rental);

        return $payment;
    }

    private function customer(Rental $rental): Customer
    {
        $customer = $rental->customer;

        if ($customer === null) {
            RentalRules::fail('Add a customer to the rental first.', 'customer_id');
        }

        return $customer;
    }

    /**
     * @return array<string, string>
     */
    private function metadata(Rental $rental): array
    {
        return [
            'yardrunner_tenant_id' => (string) $rental->tenant_id,
            'yardrunner_rental_id' => (string) $rental->id,
        ];
    }
}
