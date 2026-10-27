<?php

namespace App\Enums;

/**
 * Where a tenant stands with Yardline. Set by staff in the back office;
 * it doesn't change what the tenant can do yet.
 */
enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
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
        return $this !== self::Suspended && $this !== self::Cancelled;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
