<?php

namespace App\Http\Controllers;

use App\Access\RefundApproval;
use App\Enums\OrderStatus;
use App\Enums\PaymentTender;
use App\Enums\Permission;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Refunds\RefundPayments;
use App\Receivables\PalletLedger;
use App\Receivables\ProcessReturn;
use App\Receivables\ReturnsSettings;
use App\Receivables\UseCredit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Returns and credit notes, and pallets coming back.
 */
class ReturnController extends Controller
{
    public function index(PalletLedger $pallets): Response
    {
        return Inertia::render('returns/index', [
            'credits' => CreditNote::query()
                ->with(['organization:id,name', 'customer:id,first_name,last_name', 'order:id,number'])
                ->latest('id')
                ->paginate(40)
                ->through(fn (CreditNote $c) => [
                    'id' => $c->id,
                    'number' => $c->number,
                    'kind' => $c->kind,
                    'who' => $c->organization->name ?? $c->customer->full_name ?? '—',
                    'order_number' => $c->order?->number,
                    'reason' => $c->reason,
                    'issued_on' => $c->issued_on->toDateString(),
                    'total_cents' => $c->total_cents,
                    'remaining_cents' => $c->remainingCents(),
                    'status' => $c->status,
                ]),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name'])->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name, 'pallets_out' => $pallets->outstanding($o)]),
            'customers' => Customer::query()->whereNull('organization_id')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->full_name, 'pallets_out' => $pallets->outstanding(null, $c)]),
            'locations' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'settings' => ReturnsSettings::current()->toArray(),
            'palletsOut' => $pallets->outstanding(),
        ]);
    }

    public function create(Order $order): Response
    {
        $order->load(['lines' => fn ($q) => $q->orderBy('position')->orderBy('id'), 'organization:id,name', 'customer:id,first_name,last_name']);

        return Inertia::render('returns/create', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'who' => $order->organization->name ?? $order->customer->full_name ?? 'Walk-in',
                'lines' => $order->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => $l->description,
                    'sku' => $l->sku,
                    'unit' => $l->unit,
                    'unit_price_cents' => $l->unit_price_cents,
                    'shipped' => $l->shipped_quantity,
                    'returned' => $l->returned_quantity,
                    'returnable' => number_format(max(0, (float) $l->shipped_quantity - (float) $l->returned_quantity), 3, '.', ''),
                    'tax_rate' => $l->line_total_cents > 0 ? round($l->tax_cents / $l->line_total_cents, 4) : 0,
                ])->values(),
            ],
            'restockingPercent' => ReturnsSettings::current()->restockingPercent,
            'locations' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Order $order, ProcessReturn $process): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'lines.*.restock' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'restocking_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'location_id' => ['nullable', TenantRule::exists('locations')],
        ]);

        $credit = $process->handle(
            $order,
            array_values(array_map(fn ($l) => ['order_line_id' => (int) $l['order_line_id'], 'quantity' => (float) ($l['quantity'] ?? 0), 'restock' => (bool) ($l['restock'] ?? true)], $data['lines'])),
            $request->user(),
            $data['reason'] ?? null,
            isset($data['restocking_percent']) ? (float) $data['restocking_percent'] : null,
            isset($data['location_id']) ? Location::query()->find((int) $data['location_id']) : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Credit {$credit->number} for ".Money::format($credit->total_cents).'.']);

        return to_route('credit-notes.show', $credit);
    }

    public function show(Request $request, CreditNote $creditNote): Response
    {
        $creditNote->load(['lines', 'organization:id,name', 'customer:id,first_name,last_name', 'order:id,number', 'creator:id,name']);
        $openOrders = Order::query()
            ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Fulfilled->value])
            ->when($creditNote->organization_id, fn ($q) => $q->where('organization_id', $creditNote->organization_id), fn ($q) => $q->where('customer_id', $creditNote->customer_id))
            ->latest('id')
            ->get()
            ->filter(fn (Order $o) => $o->balanceCents() > 0)
            ->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'balance_cents' => $o->balanceCents()])
            ->values();

        return Inertia::render('returns/show', [
            'credit' => [
                ...$creditNote->only('id', 'number', 'kind', 'reason', 'subtotal_cents', 'restocking_fee_cents', 'tax_cents', 'deposit_cents', 'pallet_fee_cents', 'total_cents', 'applied_cents', 'refunded_cents', 'refund_tender', 'status', 'order_id'),
                'remaining_cents' => $creditNote->remainingCents(),
                'issued_on' => $creditNote->issued_on->toDateString(),
                'who' => $creditNote->organization->name ?? $creditNote->customer->full_name ?? '—',
                'organization_id' => $creditNote->organization_id,
                'order_number' => $creditNote->order?->number,
                'created_by' => $creditNote->creator?->name,
                'lines' => $creditNote->lines->map(fn ($l) => $l->only('id', 'description', 'quantity', 'unit', 'unit_price_cents', 'line_total_cents', 'restock'))->values(),
            ],
            'openOrders' => $openOrders,
            'tenders' => PaymentTender::manualOptions(),
            // Card payments on this account the credit can go back to.
            'cards' => $creditNote->remainingCents() > 0 ? RefundPayments::cardsFor($creditNote) : [],
            // Refunds above the user's limit need someone to approve them.
            'refundLimitCents' => $request->user()?->hasPermission(Permission::RefundAboveLimit) ? null : $request->user()?->refundLimit('goods'),
            'approvers' => $request->user() ? RefundApproval::approvers($request->user()) : [],
        ]);
    }

    public function apply(Request $request, CreditNote $creditNote, UseCredit $use): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', TenantRule::exists('orders')],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
        ]);

        $order = Order::query()->findOrFail((int) $data['order_id']);
        $use->apply($creditNote, $order, $request->user(), isset($data['amount']) ? (int) Money::parse($data['amount']) : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Credit applied to {$order->number}."]);

        return back();
    }

    public function refund(Request $request, CreditNote $creditNote, UseCredit $use, RefundPayments $refunds): RedirectResponse
    {
        $data = $request->validate([
            'tender' => ['required', Rule::enum(PaymentTender::class)->only([PaymentTender::StripeCheckout, PaymentTender::CardTerminal, PaymentTender::Cash, PaymentTender::Cheque, PaymentTender::ETransfer])],
            'payment_id' => ['nullable', 'required_if:tender,'.PaymentTender::StripeCheckout->value, TenantRule::exists('payments')],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
            'approver_id' => ['nullable', 'integer'],
            'approver_secret' => ['nullable', 'string', 'max:255'],
        ], [
            'payment_id.required_if' => 'Choose the card payment to refund to.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $tender = PaymentTender::from($data['tender']);
        $cents = isset($data['amount']) ? (int) Money::parse($data['amount']) : $creditNote->remainingCents();
        $approver = RefundApproval::check($user, $cents, 'goods', $data);

        if ($tender === PaymentTender::StripeCheckout) {
            $payment = Payment::query()->findOrFail((int) $data['payment_id']);
            $refunds->creditToCard($creditNote, $payment, $cents, $user, $approver);
            $message = Money::format($cents).' refunded to the card.';
        } else {
            $use->refund($creditNote, $tender, $user, $cents);
            $message = 'Refund recorded.';
        }

        RefundApproval::record($creditNote, $user, $approver, $cents);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function pallets(Request $request, PalletLedger $pallets): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['nullable', TenantRule::exists('organizations')],
            'customer_id' => ['nullable', TenantRule::exists('customers')],
            'count' => ['required', 'integer', 'min:1', 'max:10000'],
            'location_id' => ['required', TenantRule::exists('locations')],
        ]);

        $credit = $pallets->returned(
            isset($data['organization_id']) ? Organization::query()->find((int) $data['organization_id']) : null,
            isset($data['customer_id']) ? Customer::query()->find((int) $data['customer_id']) : null,
            (int) $data['count'],
            Location::query()->findOrFail((int) $data['location_id']),
            $request->user(),
        );

        return to_route('credit-notes.show', $credit);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'restocking_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'pallet_deposits' => ['boolean'],
            'pallet_deposit' => ['required', 'numeric', 'min:0', 'max:9999'],
            'return_window_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'yards' => ['array'],
            'yards.*' => ['boolean'],
            'pallet_usage_fees' => ['boolean'],
            'pallet_usage_fee' => ['nullable', 'required_if_accepted:pallet_usage_fees', 'numeric', 'min:0', 'max:9999'],
            'fee_yards' => ['array'],
            'fee_yards.*' => ['boolean'],
        ], [
            'pallet_usage_fee.required_if_accepted' => 'Enter the usage fee per pallet.',
        ]);

        $fees = (bool) ($data['pallet_usage_fees'] ?? false);
        $feeCents = (int) (Money::parse($data['pallet_usage_fee'] ?? null) ?? 0);

        if ($fees && $feeCents <= 0) {
            throw ValidationException::withMessages(['pallet_usage_fee' => 'Enter a usage fee above $0, or turn usage fees off.']);
        }

        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        $settings = $tenant->settings ?? [];
        $settings['returns'] = [
            'restocking_percent' => (float) $data['restocking_percent'],
            'pallet_deposits' => (bool) ($data['pallet_deposits'] ?? false),
            'pallet_deposit_cents' => (int) Money::parse($data['pallet_deposit']),
            'return_window_days' => (int) $data['return_window_days'],
            'pallet_usage_fees' => $fees,
            'pallet_usage_fee_cents' => $feeCents,
        ];
        $tenant->update(['settings' => $settings]);

        foreach ($data['yards'] ?? [] as $id => $on) {
            Location::query()->whereKey((int) $id)->update(['pallet_deposits' => (bool) $on]);
        }

        foreach ($data['fee_yards'] ?? [] as $id => $on) {
            Location::query()->whereKey((int) $id)->update(['pallet_usage_fees' => (bool) $on]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Returns, deposits and usage fees saved.']);

        return back();
    }
}
