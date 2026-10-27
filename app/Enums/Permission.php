<?php

namespace App\Enums;

/**
 * Judgment calls that cut across jobs. Each role gives some of them by
 * default (the business can change those defaults per role), and Owner
 * or Admin can grant or remove them for one person.
 */
enum Permission: string
{
    case SetManualPrices = 'set_manual_prices';
    case DiscountExempt = 'discount_exempt';
    case OverrideCredit = 'override_credit';
    case RefundAboveLimit = 'refund_above_limit';
    case CollectPaymentOnDelivery = 'collect_payment_on_delivery';
    case QualityCheckSignOff = 'quality_check_sign_off';
    case SeeCosts = 'see_costs';
    case SeeProfit = 'see_profit';
    case ApproveTimesheets = 'approve_timesheets';
    case ManageUsers = 'manage_users';

    public function label(): string
    {
        return match ($this) {
            self::SetManualPrices => 'Set manual line prices',
            self::DiscountExempt => 'Discount exempt products',
            self::OverrideCredit => 'Override credit limit',
            self::RefundAboveLimit => 'Refund above limit',
            self::CollectPaymentOnDelivery => 'Collect payment on delivery',
            self::QualityCheckSignOff => 'Quality-check sign-off',
            self::SeeCosts => 'See costs and margins',
            self::SeeProfit => 'See gross-profit P&L',
            self::ApproveTimesheets => 'Approve timesheets and export payroll',
            self::ManageUsers => 'Manage users and roles',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SetManualPrices => 'Type a price over the price list on quotes, orders and counter sales.',
            self::DiscountExempt => 'Change the price of products exempt from price lists, and pallet deposits and usage fees. Needs Set manual line prices.',
            self::OverrideCredit => 'Put an order on account above the organization’s credit limit without a card.',
            self::RefundAboveLimit => 'Refund any amount, and approve refunds above someone else’s limit.',
            self::CollectPaymentOnDelivery => 'Take payment on a COD stop on their own run.',
            self::QualityCheckSignOff => 'Sign off a quality check at any stage, whatever their role.',
            self::SeeCosts => 'See product costs and margins.',
            self::SeeProfit => 'See the gross-profit P&L report.',
            self::ApproveTimesheets => 'See who’s in, correct and approve timesheets, export payroll.',
            self::ManageUsers => 'Add people, change their roles, yards and permissions.',
        };
    }

    /** Whether each use is written to the audit log. */
    public function logged(): bool
    {
        return ! in_array($this, [self::SeeCosts, self::SeeProfit], true);
    }

    /**
     * Roles that hold this permission when a business hasn't changed it.
     *
     * @return list<UserRole>
     */
    public function defaultRoles(): array
    {
        return match ($this) {
            self::SetManualPrices => [UserRole::Owner, UserRole::Admin],
            self::DiscountExempt => [UserRole::Owner],
            self::OverrideCredit => [UserRole::Owner, UserRole::Admin, UserRole::Accounts],
            self::RefundAboveLimit => [UserRole::Owner, UserRole::Admin, UserRole::Accounts],
            self::CollectPaymentOnDelivery => [UserRole::Driver],
            // Who signs off each stage is set under Settings → Order picking.
            self::QualityCheckSignOff => [],
            self::SeeCosts => [UserRole::Owner, UserRole::Admin, UserRole::Purchasing],
            self::SeeProfit => [UserRole::Owner],
            self::ApproveTimesheets => [UserRole::Owner, UserRole::Admin],
            self::ManageUsers => [UserRole::Owner, UserRole::Admin],
        };
    }

    /**
     * Only the owner can give these. Admin can't hand out the P&L.
     */
    public function ownerGrantsOnly(): bool
    {
        return $this === self::SeeProfit;
    }

    /** A permission that means nothing without another one. */
    public function requires(): ?self
    {
        return $this === self::DiscountExempt ? self::SetManualPrices : null;
    }
}
