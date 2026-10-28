<?php

namespace App\Payments\Refunds;

use App\Audit\Audit;
use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\ApplyPayments;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Brings YardRunner in line with Stripe's refunds on a payment, from the
 * charge.refunded and charge.refund.updated webhooks. Safe to run any
 * number of times with the same event:
 *
 * - refunds made here are matched by their Stripe ID and marked settled
 *   or failed;
 * - anything Stripe has refunded beyond what YardRunner knows about (a
 *   refund made in Stripe, or one whose save was lost) is recorded as a
 *   refund off the order, so its balance and QuickBooks follow.
 */
class ReconcileStripeRefunds
{
    public function __construct(private readonly ApplyPayments $apply) {}

    /**
     * @param  int  $amountRefunded  the charge's amount_refunded, in cents
     * @param  list<array{id: string, amount: int, status: string, failure_reason?: string|null}>  $refunds  what the event carried
     */
    public function charge(Payment $payment, int $amountRefunded, array $refunds = []): void
    {
        DB::transaction(function () use ($payment, $amountRefunded, $refunds) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            foreach ($refunds as $stripe) {
                $this->settle($locked, $stripe['id'], $stripe['status'], $stripe['failure_reason'] ?? null);
            }

            $known = (int) $locked->refunds()->counting()->sum('amount_cents');
            $missing = $amountRefunded - $known;

            if ($missing <= 0) {
                return;
            }

            // Name it after a Stripe refund YardRunner hasn't seen, when
            // there's exactly one, so its later updates find it.
            $unseen = array_values(array_filter(
                $refunds,
                fn (array $r) => ! in_array($r['status'], ['failed', 'canceled'], true)
                    && ! Refund::query()->where('stripe_refund_id', $r['id'])->exists(),
            ));

            $refund = $locked->refunds()->create([
                'order_id' => $locked->order_id,
                'tender' => PaymentTender::StripeCheckout,
                'status' => PaymentState::Succeeded,
                'source' => Refund::SOURCE_STRIPE,
                'amount_cents' => $missing,
                'reason' => 'Refunded in Stripe',
                'stripe_refund_id' => count($unseen) === 1 && $unseen[0]['amount'] === $missing ? $unseen[0]['id'] : null,
                'succeeded_at' => now(),
            ]);

            $locked->refunded_cents += $missing;
            $locked->save();

            $this->apply->handle($locked->order);

            Audit::record($locked->order, 'refunded', note: Money::format($refund->amount_cents).' refunded to the card in Stripe');
        });
    }

    /**
     * One refund changed: settled, or failed after it was accepted.
     */
    public function refund(string $stripeRefundId, string $status, ?string $failureReason = null): void
    {
        $refund = Refund::query()->where('stripe_refund_id', $stripeRefundId)->first();

        if ($refund === null || $refund->payment_id === null) {
            return;
        }

        DB::transaction(function () use ($refund, $stripeRefundId, $status, $failureReason) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $this->settle($locked, $stripeRefundId, $status, $failureReason);
        });
    }

    private function settle(Payment $payment, string $stripeRefundId, string $status, ?string $failureReason): void
    {
        $refund = $payment->refunds()->where('stripe_refund_id', $stripeRefundId)->first();

        if ($refund === null || $refund->status === PaymentState::Failed) {
            return;
        }

        if ($status === 'succeeded' && $refund->status === PaymentState::Pending) {
            $refund->status = PaymentState::Succeeded;
            $refund->succeeded_at = now();
            $refund->save();

            return;
        }

        if (! in_array($status, ['failed', 'canceled'], true)) {
            return;
        }

        // The card refused the money after Stripe accepted the refund: undo
        // it, so the order or credit owes it again.
        $refund->status = PaymentState::Failed;
        $refund->failure_message = $failureReason !== null ? "The card refund failed ({$failureReason})." : 'The card refund failed.';
        $refund->save();

        $payment->refunded_cents = max(0, $payment->refunded_cents - $refund->amount_cents);
        $payment->save();

        if ($refund->credit_note_id !== null) {
            $credit = CreditNote::query()->lockForUpdate()->find($refund->credit_note_id);

            if ($credit !== null) {
                $credit->refunded_cents = max(0, $credit->refunded_cents - $refund->amount_cents);
                $credit->syncStatus();
                $credit->save();
                Audit::record($credit, 'refund failed', note: Money::format($refund->amount_cents).' card refund failed and is back on the credit. '.$refund->failure_message);
            }

            return;
        }

        $this->apply->handle($payment->order);
        Audit::record($payment->order, 'refund failed', note: Money::format($refund->amount_cents).' card refund failed. '.$refund->failure_message);
    }
}
