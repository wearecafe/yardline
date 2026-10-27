<?php

namespace App\Billing;

use App\Models\Employee;
use App\Models\Tenant;
use App\Tenancy\TenantScope;
use App\Time\TimeSettings;

/**
 * What a tenant's subscription is made of: the base subscription, Rentals
 * if they've switched it on, and Time & attendance per person whose time
 * is tracked (active employees with "tracks time" on).
 */
final readonly class Plan
{
    public const BASE = 'base';

    public const RENTALS = 'rentals';

    public const TIME = 'time';

    public function __construct(
        public bool $rentals,
        public bool $time,
        public int $seats,
    ) {}

    public static function for(Tenant $tenant): self
    {
        $time = TimeSettings::for($tenant)->enabled;

        return new self(
            rentals: (bool) $tenant->rentals_enabled,
            time: $time,
            seats: $time ? self::seatsFor($tenant) : 0,
        );
    }

    /**
     * People whose time the tenant tracks, billed per person.
     */
    public static function seatsFor(Tenant $tenant): int
    {
        return Employee::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->tracked()
            ->count();
    }

    /**
     * Quantity of each item, by key. Time & attendance with nobody tracked
     * is left off rather than billed at zero.
     *
     * @return array<string, int>
     */
    public function quantities(): array
    {
        return array_filter([
            self::BASE => 1,
            self::RENTALS => $this->rentals ? 1 : 0,
            self::TIME => $this->time ? $this->seats : 0,
        ]);
    }

    /**
     * Before tax.
     */
    public function monthlyCents(): int
    {
        $total = 0;

        foreach ($this->quantities() as $key => $quantity) {
            $total += self::amount($key) * $quantity;
        }

        return $total;
    }

    /** For back office reporting: see yardline.back_office.plans. */
    public function key(): string
    {
        return $this->rentals ? 'yard_rentals' : 'yard';
    }

    /**
     * Lines for the billing page and back office.
     *
     * @return list<array{key: string, name: string, unit_cents: int, quantity: int, per_user: bool, total_cents: int, on: bool}>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ([self::BASE => true, self::RENTALS => $this->rentals, self::TIME => $this->time] as $key => $on) {
            $quantity = match ($key) {
                self::TIME => $this->seats,
                default => 1,
            };

            $lines[] = [
                'key' => $key,
                'name' => self::name($key),
                'unit_cents' => self::amount($key),
                'quantity' => $quantity,
                'per_user' => self::perUser($key),
                'total_cents' => $on ? self::amount($key) * $quantity : 0,
                'on' => $on,
            ];
        }

        return $lines;
    }

    /**
     * The published price list, for the marketing home page.
     *
     * @return array{currency: string, trial_days: int, items: list<array{key: string, name: string, amount_cents: int, per_user: bool}>}
     */
    public static function catalog(): array
    {
        $items = [];

        foreach (array_keys((array) config('yardline.billing.items')) as $key) {
            $key = (string) $key;
            $items[] = ['key' => $key, 'name' => self::name($key), 'amount_cents' => self::amount($key), 'per_user' => self::perUser($key)];
        }

        return [
            'currency' => strtoupper((string) config('yardline.billing.currency', 'cad')),
            'trial_days' => (int) config('yardline.billing.trial_days', 14),
            'items' => $items,
        ];
    }

    public static function amount(string $key): int
    {
        return (int) config("yardline.billing.items.{$key}.amount_cents");
    }

    public static function name(string $key): string
    {
        return (string) config("yardline.billing.items.{$key}.name");
    }

    public static function lookupKey(string $key): string
    {
        return (string) config("yardline.billing.items.{$key}.lookup_key");
    }

    public static function perUser(string $key): bool
    {
        return (bool) config("yardline.billing.items.{$key}.per_user", false);
    }

    /**
     * Item key for a Stripe price lookup key, or null if it isn't one of ours.
     */
    public static function keyForLookup(?string $lookupKey): ?string
    {
        foreach (array_keys((array) config('yardline.billing.items')) as $key) {
            if ($lookupKey !== null && self::lookupKey((string) $key) === $lookupKey) {
                return (string) $key;
            }
        }

        return null;
    }
}
