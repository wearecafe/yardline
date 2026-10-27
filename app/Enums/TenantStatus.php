<?php

namespace App\Enums;

/**
 * Where a tenant stands with YardRunner. Worked out from their trial and
 * Stripe subscription (App\Billing\BillingState); staff can only suspend.
 */
enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Expired => 'Trial ended',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Counted in MRR. */
    public function isBillable(): bool
    {
        return $this === self::Active || $this === self::PastDue;
    }

    /** Still a customer (or about to be): counted as a live tenant. */
    public function isLive(): bool
    {
        return $this === self::Trial || $this === self::Active || $this === self::PastDue;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
