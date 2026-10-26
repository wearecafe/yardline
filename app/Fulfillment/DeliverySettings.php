<?php

namespace App\Fulfillment;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;

/**
 * How a yard charges for delivering goods, kept in
 * tenants.settings['delivery']. Each yard picks one method:
 *
 *  - zone: a flat fee per delivery zone (by postal code prefix)
 *  - distance: a base fee plus a rate per km from the yard
 *  - truckload: a fee per truckload, with loads worked out from weight
 */
final readonly class DeliverySettings
{
    public const METHODS = ['zone' => 'By zone', 'distance' => 'By distance', 'truckload' => 'By truckload'];

    public function __construct(
        public string $method = 'zone',
        public int $baseCents = 4500,
        public int $perKmCents = 350,
        public int $perLoadCents = 12500,
        public int $loadCapacityKg = 14000,
        public int $outOfZoneCents = 0,
        public bool $etaTexts = true,
    ) {}

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['delivery'] ?? [];

        return new self(
            method: in_array($v['method'] ?? 'zone', array_keys(self::METHODS), true) ? (string) ($v['method'] ?? 'zone') : 'zone',
            baseCents: (int) ($v['base_cents'] ?? 4500),
            perKmCents: (int) ($v['per_km_cents'] ?? 350),
            perLoadCents: (int) ($v['per_load_cents'] ?? 12500),
            loadCapacityKg: max(1, (int) ($v['load_capacity_kg'] ?? 14000)),
            outOfZoneCents: (int) ($v['out_of_zone_cents'] ?? 0),
            etaTexts: (bool) ($v['eta_texts'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'base_cents' => $this->baseCents,
            'per_km_cents' => $this->perKmCents,
            'per_load_cents' => $this->perLoadCents,
            'load_capacity_kg' => $this->loadCapacityKg,
            'out_of_zone_cents' => $this->outOfZoneCents,
            'eta_texts' => $this->etaTexts,
        ];
    }
}
