<?php

namespace App\Credit;

use App\Models\Order;
use App\Models\Organization;

/**
 * Decides whether an order may be charged to an organization's account.
 * Card (or other prepayment) is required when an order would go over the
 * limit, unless an owner or admin overrides.
 */
class CreditCheck
{
    /**
     * Credit in use: unpaid balances on confirmed or fulfilled account orders.
     */
    public function usedCents(Organization $organization, ?Order $excluding = null): int
    {
        return (int) $organization->orders()
            ->openOnAccount()
            ->when($excluding, fn ($query) => $query->whereKeyNot($excluding->getKey()))
            ->get(['id', 'total_cents', 'deposit_cents', 'amount_paid_cents'])
            ->sum(fn (Order $order) => $order->balanceCents());
    }

    public function availableCents(Organization $organization): int
    {
        return max(0, $organization->credit_limit_cents - $this->usedCents($organization));
    }

    public function assess(Order $order): CreditAssessment
    {
        $organization = $order->organization;
        $required = $order->amountDueCents();

        if ($order->isRental()) {
            return new CreditAssessment(CreditAssessment::RENTAL, 0, 0, $required);
        }

        if ($organization === null) {
            return new CreditAssessment(CreditAssessment::NO_ACCOUNT, 0, 0, $required);
        }

        $limit = $organization->credit_limit_cents;
        $used = $this->usedCents($organization, $order);

        $outcome = match (true) {
            $organization->credit_hold => CreditAssessment::CREDIT_HOLD,
            $limit === 0 => CreditAssessment::NO_CREDIT,
            $used + $required > $limit => CreditAssessment::OVER_LIMIT,
            default => CreditAssessment::APPROVED,
        };

        return new CreditAssessment($outcome, $limit, $used, $required);
    }
}
