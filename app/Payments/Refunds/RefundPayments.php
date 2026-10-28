<?php

namespace App\Payments\Refunds;

use App\Audit\Audit;
use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Orders\OrderRules;
use App\Payments\ApplyPayments;
use App\Support\Money;
use App\Support\StripeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pays money back out, on an order payment or a credit note.
 *
 * Card refunds go to Stripe inside the same database transaction that
 * records them, with the payment row locked: if Stripe refuses, nothing is
 * recorded; if the refund goes through but the save doesn't, the
 * charge.refunded webhook finds the difference and records it. The lock
 * also makes the webhook wait, so a refund made here is never counted twice.
 *
 * Refund limits are checked by the caller (RefundApproval) before this runs.
 */
class RefundPayments
{
    public function __construct(
        private readonly CardRefunds $cards,
        private readonly ApplyPayments $apply,
    ) {}

    /**
     * Refund part or all of an order payment, the way it came in: card
     * payments to the card through Stripe, account credit back onto its
     * credit note, anything else by hand in the same way (cash as cash, a
     * terminal payment on the terminal).
     */
    public function payment(Payment $payment, int $cents, User $user, ?User $approver = null, ?string $reason = null): Refund
    {
        if ($payment->tender === PaymentTender::StripeCheckout && $payment->stripe_payment_intent_id === null) {
            OrderRules::fail('This card payment has no Stripe reference, so it can’t go back to the card.', 'amount');
        }

        $refund = DB::transaction(function () use ($payment, $cents, $user, $approver, $reason) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = $locked->order;

            if ($locked->status !== PaymentState::Succeeded) {
                OrderRules::fail('Only payments that went through can be refunded.', 'amount');
            }

            $most = min($locked->netCents(), $locked->refundableCents());

            if ($cents <= 0) {
                OrderRules::fail('Enter an amount above zero.', 'amount');
            }

            if ($cents > $most) {
                OrderRules::fail($most > 0 ? 'That’s more than is left on the payment ('.Money::format($most).').' : 'This payment has already been refunded.', 'amount');
            }

            $refund = new Refund([
                'order_id' => $order->id,
                'payment_id' => $locked->id,
                'tender' => $locked->tender,
                'amount_cents' => $cents,
                'reason' => $reason,
                'refunded_by' => $user->id,
                'approved_by' => $approver?->id,
            ]);

            if ($locked->tender === PaymentTender::StripeCheckout) {
                $this->toCard($refund, $locked, ['yardline_order_id' => (string) $order->id]);
            } else {
                $refund->status = PaymentState::Succeeded;
                $refund->succeeded_at = now();
                $refund->save();
            }

            if ($locked->tender === PaymentTender::CreditNote) {
                $this->backOnCredit($locked, $cents);
            }

            $locked->refunded_cents += $cents;
            $locked->save();

            $this->apply->handle($order->refresh());

            return $refund;
        });

        Audit::record($payment->order, 'refunded', note: $this->describe($refund));

        return $refund;
    }

    /**
     * Pay a credit note back onto a card the customer paid with. The card
     * payment's order is left as it is; only the credit is spent.
     */
    public function creditToCard(CreditNote $credit, Payment $payment, int $cents, User $user, ?User $approver = null): Refund
    {
        if (! self::sameAccount($credit, $payment)) {
            OrderRules::fail('That card payment belongs to another account.', 'payment_id');
        }

        if (! $payment->isStripeCard()) {
            OrderRules::fail('Only card payments taken through YardRunner can be refunded to the card.', 'payment_id');
        }

        $refund = DB::transaction(function () use ($credit, $payment, $cents, $user, $approver) {
            /** @var CreditNote $lockedCredit */
            $lockedCredit = CreditNote::query()->lockForUpdate()->findOrFail($credit->id);
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($cents <= 0 || $cents > $lockedCredit->remainingCents()) {
                OrderRules::fail('There’s not that much left on the credit.', 'amount');
            }

            if ($cents > $locked->refundableCents()) {
                OrderRules::fail('That card payment only has '.Money::format($locked->refundableCents()).' left to refund. Refund the rest another way.', 'amount');
            }

            $refund = new Refund([
                'order_id' => $locked->order_id,
                'payment_id' => $locked->id,
                'credit_note_id' => $lockedCredit->id,
                'tender' => PaymentTender::StripeCheckout,
                'amount_cents' => $cents,
                'reason' => "Refund of {$lockedCredit->number}",
                'refunded_by' => $user->id,
                'approved_by' => $approver?->id,
            ]);

            $this->toCard($refund, $locked, ['yardline_credit_note_id' => (string) $lockedCredit->id]);

            $locked->refunded_cents += $cents;
            $locked->save();

            $lockedCredit->refunded_cents += $cents;
            $lockedCredit->refund_tender = PaymentTender::StripeCheckout->value;
            $lockedCredit->syncStatus();
            $lockedCredit->save();

            return $refund;
        });

        $credit->refresh();

        return $refund;
    }

    /**
     * Card payments a credit note can be refunded to: the customer's own,
     * taken through YardRunner in the last year, with something left to
     * refund. The credit's own order comes first.
     *
     * @return list<array{id: int, label: string, refundable_cents: int}>
     */
    public static function cardsFor(CreditNote $credit): array
    {
        if ($credit->organization_id === null && $credit->customer_id === null) {
            return [];
        }

        return array_values(Payment::query()
            ->with('order:id,number,organization_id,customer_id')
            ->where('tender', PaymentTender::StripeCheckout->value)
            ->where('status', PaymentState::Succeeded->value)
            ->whereNotNull('stripe_payment_intent_id')
            ->whereColumn('refunded_cents', '<', 'amount_cents')
            ->where('succeeded_at', '>=', now()->subYear())
            ->whereHas('order', fn ($q) => $credit->organization_id !== null
                ? $q->where('organization_id', $credit->organization_id)
                : $q->whereNull('organization_id')->where('customer_id', $credit->customer_id))
            ->latest('succeeded_at')
            ->limit(20)
            ->get()
            ->sortBy(fn (Payment $p) => $p->order_id === $credit->order_id ? 0 : 1)
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'label' => "{$p->order->number}, ".$p->succeeded_at?->format('M j').', '.Money::format($p->refundableCents()).' left on the card',
                'refundable_cents' => $p->refundableCents(),
            ])
            ->values()
            ->all());
    }

    public static function sameAccount(CreditNote $credit, Payment $payment): bool
    {
        $order = $payment->order;

        return $credit->organization_id !== null
            ? $credit->organization_id === $order->organization_id
            : $credit->customer_id !== null && $order->organization_id === null && $credit->customer_id === $order->customer_id;
    }

    /**
     * Send the refund to Stripe and save it with what Stripe said. Runs
     * inside the caller's transaction, so a refusal rolls everything back.
     *
     * @param  array<string, string>  $metadata
     */
    private function toCard(Refund $refund, Payment $payment, array $metadata): void
    {
        // Saved first so Stripe's metadata can name it. The idempotency key
        // makes the client's network retries safe.
        $refund->status = PaymentState::Pending;
        $refund->save();

        try {
            $result = $this->cards->refund($payment, $refund->amount_cents, "yardrunner-refund-{$refund->id}-".Str::uuid(), [
                'yardline_tenant_id' => (string) $refund->tenant_id,
                'yardline_refund_id' => (string) $refund->id,
                'yardline_payment_id' => (string) $payment->id,
                ...$metadata,
            ]);
        } catch (CardRefundFailed $e) {
            OrderRules::fail($e->getMessage(), 'amount');
        }

        if ($result->failed()) {
            OrderRules::fail('The card refund was declined. Refund it another way.', 'amount');
        }

        $refund->stripe_refund_id = $result->id;
        $refund->fee_returned_cents = StripeGateway::applicationFeeReturned($payment->amount_cents, $refund->amount_cents);

        // Card refunds are nearly always immediate; a pending one still
        // counts, and the webhook settles it.
        if ($result->status === 'succeeded') {
            $refund->status = PaymentState::Succeeded;
            $refund->succeeded_at = now();
        }

        $refund->save();
    }

    /** Account credit that paid for an order goes back on its credit note. */
    private function backOnCredit(Payment $payment, int $cents): void
    {
        $credit = CreditNote::query()->lockForUpdate()->where('number', $payment->reference)->first();

        if ($credit === null) {
            OrderRules::fail("Credit note {$payment->reference} can’t be found to put the credit back on.", 'amount');
        }

        $credit->applied_cents = max(0, $credit->applied_cents - $cents);
        $credit->syncStatus();
        $credit->save();
    }

    private function describe(Refund $refund): string
    {
        $how = match ($refund->tender) {
            PaymentTender::StripeCheckout => 'to the card',
            PaymentTender::CreditNote => 'back on '.($refund->payment->reference ?? 'the credit note'),
            default => 'by '.mb_strtolower($refund->tender->label()),
        };

        return Money::format($refund->amount_cents)." refunded {$how}".($refund->reason ? ": {$refund->reason}" : '');
    }
}
