<?php

namespace App\Orders;

use App\Credit\CreditAssessment;
use App\Credit\CreditCheck;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\Settlement;
use App\Enums\StopStatus;
use App\Models\Order;
use App\Models\User;
use App\Tax\TaxCalculationFailed;
use Illuminate\Support\Facades\DB;

/**
 * Locks in a draft: calculates tax, decides how it will be paid, and (for
 * account orders) commits tax and draws on the organization's credit.
 *
 * Rules:
 *  - Rentals are always prepaid, with the deposit due up front.
 *  - Walk-ins (no organization) are prepaid.
 *  - Account orders must fit within available credit, unless an owner or
 *    admin overrides with a reason.
 */
class ConfirmOrder
{
    public function __construct(
        private readonly RecalculateOrder $recalculate,
        private readonly CreditCheck $credit,
        private readonly CommitOrderTax $commitTax,
    ) {}

    public function handle(Order $order, Settlement $settlement, User $user, ?string $overrideReason = null): Order
    {
        if (! $order->isDraft()) {
            OrderRules::fail('This order has already been confirmed.');
        }

        if ($order->lines()->doesntExist()) {
            OrderRules::fail('Add at least one line before confirming.');
        }

        if ($order->isRental() && ($order->rental_starts_at === null || $order->rental_ends_at === null)) {
            OrderRules::fail('Set the rental start and end dates before confirming.');
        }

        $order->loadMissing(['organizationLocation', 'customer']);

        if ($order->organizationLocation?->po_required && blank($order->po_number)) {
            OrderRules::fail("{$order->organizationLocation->name} needs a PO number on every order. Add one under Details.");
        }

        if ($settlement === Settlement::Account && $order->customer !== null && $order->organization_id !== null) {
            $membership = $order->customer->membershipIn($order->organization_id);

            if ($membership !== null && ! $membership->can_charge_account) {
                OrderRules::fail("{$order->customer->full_name} can’t charge to this account. Take a card, or have an approver place the order.");
            }
        }

        try {
            $this->recalculate->handle($order, withTax: true);
        } catch (TaxCalculationFailed $e) {
            OrderRules::fail($e->getMessage());
        }

        $overrideBy = null;

        if ($settlement === Settlement::Account) {
            $assessment = $this->credit->assess($order);

            if (! $assessment->approved()) {
                $this->guardOverride($assessment, $user, $overrideReason);
                $overrideBy = $user->id;
            }
        }

        DB::transaction(function () use ($order, $settlement, $overrideBy, $overrideReason) {
            $order->settlement = $settlement;
            $order->status = OrderStatus::Confirmed;
            $order->confirmed_at = now();
            $order->credit_override_by = $overrideBy;
            $order->credit_override_reason = $overrideBy ? $overrideReason : null;
            $order->save();
        });

        // Deliveries go on the dispatch board to plan a truck.
        if ($order->fulfillment === FulfillmentMethod::Delivery && ! $order->isRental()) {
            $order->stops()->firstOrCreate(['kind' => 'delivery'], [
                'status' => StopStatus::Unscheduled,
                'date' => $order->requested_on?->toDateString(),
                'address' => $order->delivery_address,
                'needs' => $order->needs_boom ? 'Needs boom truck' : null,
            ]);
        }

        // Account orders are a completed sale now. Prepaid orders commit tax
        // when they're paid in full (see ApplyPayments).
        if ($settlement === Settlement::Account) {
            $this->commitTax->handle($order);
        }

        return $order;
    }

    private function guardOverride(CreditAssessment $assessment, User $user, ?string $reason): void
    {
        if (! $assessment->overridable()) {
            OrderRules::fail($assessment->message());
        }

        if (blank($reason)) {
            OrderRules::fail($assessment->message(), 'override_reason');
        }

        if (! $user->canManageBusiness()) {
            OrderRules::fail('Only an owner or admin can override the credit limit.', 'override_reason');
        }
    }
}
