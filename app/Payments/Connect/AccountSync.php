<?php

namespace App\Payments\Connect;

use App\Models\Tenant;

/**
 * Copies a connected account's status from Stripe onto the tenant: from
 * account.updated webhooks and when the yard leaves onboarding.
 */
class AccountSync
{
    /**
     * @param  array<string, mixed>  $account  Stripe's Account object, as an array
     */
    public static function apply(Tenant $tenant, array $account): void
    {
        /** @var array<string, mixed> $requirements */
        $requirements = is_array($account['requirements'] ?? null) ? $account['requirements'] : [];

        $tenant->forceFill([
            'stripe_charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
            'stripe_payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
            'stripe_details_submitted' => (bool) ($account['details_submitted'] ?? false),
            'stripe_requirements' => [
                'currently_due' => self::strings($requirements['currently_due'] ?? []),
                'past_due' => self::strings($requirements['past_due'] ?? []),
                'disabled_reason' => is_string($requirements['disabled_reason'] ?? null) ? $requirements['disabled_reason'] : null,
                'current_deadline' => is_int($requirements['current_deadline'] ?? null) ? $requirements['current_deadline'] : null,
            ],
            'stripe_account_synced_at' => now(),
        ])->save();
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
