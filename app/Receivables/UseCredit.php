<?php

namespace App\Receivables;

use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderRules;
use App\Payments\ApplyPayments;
use App\Payments\RecordPayment;
use Illuminate\Support\Facades\DB;

/**
 * A credit note is spent by applying it to an order on the same account,
 * or by refunding it.
 */
class UseCredit
{
    public function __construct(
        private readonly RecordPayment $payments,
        private readonly ApplyPayments $apply,
    ) {}

    public function apply(CreditNote $credit, Order $order, User $user, ?int $cents = null): CreditNote
    {
        $sameAccount = $credit->organization_id !== null
            ? $credit->organization_id === $order->organization_id
            : $credit->customer_id !== null && $credit->customer_id === $order->customer_id;

        if (! $sameAccount) {
            OrderRules::fail('That credit belongs to another account.', 'order_id');
        }

        $cents ??= min($credit->remainingCents(), $order->balanceCents());

        if ($cents <= 0 || $cents > $credit->remainingCents()) {
            OrderRules::fail('There’s not that much left on the credit.', 'amount');
        }

        $this->payments->guard($order, $cents);

        return DB::transaction(function () use ($credit, $order, $user, $cents) {
            $order->payments()->create([
                'tender' => PaymentTender::CreditNote,
                'status' => PaymentState::Succeeded,
                'amount_cents' => $cents,
                'reference' => $credit->number,
                'recorded_by' => $user->id,
                'succeeded_at' => now(),
            ]);
            $this->apply->handle($order);

            $credit->applied_cents += $cents;
            $credit->syncStatus();
            $credit->save();

            return $credit;
        });
    }

    public function refund(CreditNote $credit, PaymentTender $tender, User $user, ?int $cents = null): CreditNote
    {
        $cents ??= $credit->remainingCents();

        if ($cents <= 0 || $cents > $credit->remainingCents()) {
            OrderRules::fail('There’s not that much left on the credit.', 'amount');
        }

        $credit->refunded_cents += $cents;
        $credit->refund_tender = $tender->value;
        $credit->syncStatus();
        $credit->save();

        return $credit;
    }
}
