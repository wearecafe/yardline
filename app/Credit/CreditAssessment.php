<?php

namespace App\Credit;

final readonly class CreditAssessment
{
    public const APPROVED = 'approved';

    public const NO_ACCOUNT = 'no_account';

    public const RENTAL = 'rental';

    public const CREDIT_HOLD = 'credit_hold';

    public const NO_CREDIT = 'no_credit';

    public const OVER_LIMIT = 'over_limit';

    public function __construct(
        public string $outcome,
        public int $limitCents,
        public int $usedCents,
        public int $requiredCents,
    ) {}

    public function approved(): bool
    {
        return $this->outcome === self::APPROVED;
    }

    /**
     * Whether an owner or admin may push this through on account anyway.
     */
    public function overridable(): bool
    {
        return in_array($this->outcome, [self::CREDIT_HOLD, self::NO_CREDIT, self::OVER_LIMIT], true);
    }

    public function availableCents(): int
    {
        return max(0, $this->limitCents - $this->usedCents);
    }

    public function message(): string
    {
        return match ($this->outcome) {
            self::APPROVED => 'Within the credit limit.',
            self::NO_ACCOUNT => 'Walk-in orders are prepaid. Attach an organization to charge to account.',
            self::RENTAL => 'Rentals are prepaid with a deposit and invoiced separately.',
            self::CREDIT_HOLD => 'This organization is on credit hold. Take payment, or override.',
            self::NO_CREDIT => 'This organization has no credit limit. Take payment, or override.',
            self::OVER_LIMIT => 'This order would go over the available credit. Take payment, or override.',
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'approved' => $this->approved(),
            'overridable' => $this->overridable(),
            'message' => $this->message(),
            'limit_cents' => $this->limitCents,
            'used_cents' => $this->usedCents,
            'available_cents' => $this->availableCents(),
            'required_cents' => $this->requiredCents,
        ];
    }
}
