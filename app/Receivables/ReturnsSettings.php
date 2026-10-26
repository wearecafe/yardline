<?php

namespace App\Receivables;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;

/**
 * Returns and pallet deposits, kept in tenants.settings['returns'].
 * Each yard also turns pallet deposits on or off for itself.
 */
final readonly class ReturnsSettings
{
    public function __construct(
        public float $restockingPercent = 15.0,
        public bool $palletDeposits = true,
        public int $palletDepositCents = 2500,
        public int $returnWindowDays = 30,
    ) {}

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['returns'] ?? [];

        return new self(
            restockingPercent: (float) ($v['restocking_percent'] ?? 15),
            palletDeposits: (bool) ($v['pallet_deposits'] ?? true),
            palletDepositCents: (int) ($v['pallet_deposit_cents'] ?? 2500),
            returnWindowDays: (int) ($v['return_window_days'] ?? 30),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'restocking_percent' => $this->restockingPercent,
            'pallet_deposits' => $this->palletDeposits,
            'pallet_deposit_cents' => $this->palletDepositCents,
            'return_window_days' => $this->returnWindowDays,
        ];
    }
}
