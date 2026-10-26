<?php

namespace App\Rentals;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;

/**
 * A yard's rental policy, kept in tenants.settings['rentals'].
 */
final readonly class RentalSettings
{
    public const DEFAULT_TERMS = <<<'TXT'
        [YOUR RENTAL TERMS AND CONDITIONS: care and use, operator responsibility,
        damage and theft, fuel and cleaning, late returns, cancellation.
        Have these reviewed by your lawyer before using them with customers.]
        TXT;

    public function __construct(
        public float $damageWaiverPercent = 12.0,
        public bool $freeSundayWithSaturday = true,
        public int $lateGraceMinutes = 60,
        public string $lateFeePolicy = 'Each late day is charged at the day rate.',
        public string $terms = self::DEFAULT_TERMS,
        public int $reminderHoursBeforeReturn = 24,
        public string $openingTime = '07:00',
        public string $closingTime = '17:00',
    ) {}

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $values = $tenant?->settings['rentals'] ?? [];

        return new self(
            damageWaiverPercent: (float) ($values['damage_waiver_percent'] ?? 12),
            freeSundayWithSaturday: (bool) ($values['free_sunday_with_saturday'] ?? true),
            lateGraceMinutes: (int) ($values['late_grace_minutes'] ?? 60),
            lateFeePolicy: (string) ($values['late_fee_policy'] ?? 'Each late day is charged at the day rate.'),
            terms: (string) (($values['terms'] ?? null) ?: self::DEFAULT_TERMS),
            reminderHoursBeforeReturn: (int) ($values['reminder_hours_before_return'] ?? 24),
            openingTime: (string) ($values['opening_time'] ?? '07:00'),
            closingTime: (string) ($values['closing_time'] ?? '17:00'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'damage_waiver_percent' => $this->damageWaiverPercent,
            'free_sunday_with_saturday' => $this->freeSundayWithSaturday,
            'late_grace_minutes' => $this->lateGraceMinutes,
            'late_fee_policy' => $this->lateFeePolicy,
            'terms' => $this->terms,
            'reminder_hours_before_return' => $this->reminderHoursBeforeReturn,
            'opening_time' => $this->openingTime,
            'closing_time' => $this->closingTime,
        ];
    }
}
