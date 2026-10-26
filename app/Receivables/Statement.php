<?php

namespace App\Receivables;

use App\Enums\PaymentState;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * An account statement: open invoices with their age, recent payments and
 * credits, and what's owed now.
 */
class Statement
{
    public function __construct(private readonly PalletLedger $pallets) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::today($organization->tenant->timezone ?? 'America/Toronto');
        $since = $on->subDays(60);

        $open = Invoice::query()->with('order')->where('organization_id', $organization->id)->where('status', 'open')->orderBy('due_on')->get();
        $buckets = array_fill_keys(array_keys(Aging::BUCKETS), 0);

        $invoices = $open->map(function (Invoice $invoice) use ($on, &$buckets) {
            $balance = $invoice->balanceCents();
            $days = $invoice->daysOverdue($on);
            $buckets[Aging::bucket($days)] += $balance;

            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'order_id' => $invoice->order_id,
                'order_number' => $invoice->order->number,
                'po_number' => $invoice->order->po_number,
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on->toDateString(),
                'total_cents' => $invoice->order->amountDueCents(),
                'paid_cents' => $invoice->order->amount_paid_cents,
                'balance_cents' => $balance,
                'days_overdue' => $days,
            ];
        })->values();

        $payments = Payment::query()
            ->with('order:id,number,organization_id')
            ->whereHas('order', fn ($q) => $q->where('organization_id', $organization->id))
            ->where('status', PaymentState::Succeeded->value)
            ->where('succeeded_at', '>=', $since)
            ->latest('succeeded_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'date' => $p->succeeded_at?->toDateString(),
                'order_number' => $p->order->number,
                'tender' => $p->tender->label(),
                'reference' => $p->reference,
                'amount_cents' => $p->amount_cents,
            ])->values();

        $credits = CreditNote::query()->where('organization_id', $organization->id)
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('issued_on', '>=', $since->toDateString()))
            ->latest('issued_on')
            ->get()
            ->map(fn (CreditNote $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'issued_on' => $c->issued_on->toDateString(),
                'reason' => $c->reason,
                'total_cents' => $c->total_cents,
                'remaining_cents' => $c->remainingCents(),
            ])->values();

        $owing = array_sum($buckets);
        $credit = (int) $credits->sum('remaining_cents');

        return [
            'date' => $on->toDateString(),
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'account_number' => $organization->account_number,
                'email' => $organization->email,
                'address' => array_filter([$organization->address_line1, $organization->address_line2, trim(implode(' ', array_filter([$organization->city, $organization->region, $organization->postal_code])))]),
                'terms' => $organization->payment_terms->label(),
                'credit_limit_cents' => $organization->credit_limit_cents,
            ],
            'invoices' => $invoices,
            'payments' => $payments,
            'credits' => $credits,
            'buckets' => $buckets,
            'bucket_labels' => Aging::BUCKETS,
            'owing_cents' => $owing,
            'credit_cents' => $credit,
            'balance_cents' => $owing - $credit,
            'balance' => Money::format($owing - $credit),
            'pallets_out' => $this->pallets->outstanding($organization),
        ];
    }
}
