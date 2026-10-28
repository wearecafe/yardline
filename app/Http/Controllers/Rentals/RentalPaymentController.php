<?php

namespace App\Http\Controllers\Rentals;

use App\Access\YardScope;
use App\Enums\DepositStatus;
use App\Enums\PaymentState;
use App\Enums\RentalStatus;
use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Models\RentalPayment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rental money: what's been taken, deposits on hold, and what's owing.
 * Rental revenue is invoiced separately from goods.
 */
class RentalPaymentController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $rentals = YardScope::apply(Rental::query(), $user)->select('id');

        $payments = RentalPayment::query()
            ->whereIn('rental_id', $rentals)
            ->with(['rental.organization:id,name', 'rental.customer:id,first_name,last_name', 'recordedBy:id,name'])
            ->where('status', PaymentState::Succeeded->value)
            ->latest('succeeded_at')
            ->limit(200)
            ->get();

        $held = YardScope::apply(Rental::query(), $user)
            ->with(['organization:id,name', 'customer:id,first_name,last_name'])
            ->where('deposit_status', DepositStatus::Held->value)
            ->orderBy('ends_at')
            ->get();

        $owing = YardScope::apply(Rental::query(), $user)
            ->with(['organization:id,name', 'customer:id,first_name,last_name'])
            ->whereIn('status', [RentalStatus::Reserved->value, RentalStatus::Out->value, RentalStatus::Returned->value])
            ->whereColumn('amount_paid_cents', '<', 'total_cents')
            ->orderBy('starts_at')
            ->get();

        $since = now()->startOfMonth();

        return Inertia::render('rentals/payments', [
            'stats' => [
                'month_cents' => (int) $payments->filter(fn ($p) => in_array($p->kind->value, ['charge', 'deposit_capture'], true) && $p->succeeded_at >= $since)->sum('amount_cents')
                    - (int) $payments->filter(fn ($p) => $p->kind->value === 'refund' && $p->succeeded_at >= $since)->sum('amount_cents'),
                'held_cents' => (int) $held->sum('deposit_cents'),
                'owing_cents' => (int) $owing->sum(fn (Rental $rental) => $rental->balanceCents()),
            ],
            'payments' => $payments->map(fn (RentalPayment $payment) => [
                ...$payment->only('id', 'kind', 'tender', 'amount_cents', 'reference'),
                'rental_id' => $payment->rental_id,
                'number' => $payment->rental->number,
                'customer' => $payment->rental->customerName(),
                'succeeded_at' => $payment->succeeded_at?->toIso8601String(),
                'recorded_by' => $payment->recordedBy?->name,
            ]),
            'held' => $held->map(fn (Rental $rental) => [
                'id' => $rental->id,
                'number' => $rental->number,
                'customer' => $rental->customerName(),
                'deposit_cents' => $rental->deposit_cents,
                'ends_at' => $rental->ends_at->toIso8601String(),
                'status' => $rental->displayStatus(),
            ]),
            'owing' => $owing->map(fn (Rental $rental) => [
                'id' => $rental->id,
                'number' => $rental->number,
                'customer' => $rental->customerName(),
                'balance_cents' => $rental->balanceCents(),
                'status' => $rental->displayStatus(),
            ]),
        ]);
    }
}
