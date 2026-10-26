<?php

namespace App\Accounting\QuickBooks;

use App\Models\Integration;

/**
 * Which QuickBooks account each kind of Yardline line and payment posts to.
 * The yard picks from its own chart of accounts; the defaults are names
 * to look for, or to create if missing.
 */
final class AccountMapping
{
    /**
     * key => [label, QuickBooks account types it may be, default name]
     *
     * @var array<string, array{0: string, 1: list<string>, 2: string}>
     */
    public const KEYS = [
        'sales' => ['Product sales', ['Income'], 'Sales'],
        'delivery' => ['Delivery fees', ['Income'], 'Delivery income'],
        'rental' => ['Rentals, fuel, cleaning and damage', ['Income'], 'Rental income'],
        'damage_waiver' => ['Damage waiver', ['Income'], 'Damage waiver income'],
        'restocking' => ['Restocking fees', ['Income'], 'Restocking fees'],
        'adjustments' => ['Credit adjustments', ['Income'], 'Sales discounts and allowances'],
        'pallet_deposits' => ['Pallet deposits held', ['Other Current Liability'], 'Pallet deposits held'],
        'stripe_clearing' => ['Card and payment-link payments', ['Bank', 'Other Current Asset'], 'Stripe clearing'],
        'undeposited' => ['Cash, cheque and e-Transfer', ['Other Current Asset'], 'Undeposited Funds'],
    ];

    /**
     * @return array<string, string|null> key => QuickBooks account id
     */
    public static function for(Integration $integration): array
    {
        $saved = $integration->settings['accounts'] ?? [];

        return collect(self::KEYS)->mapWithKeys(fn ($def, $key) => [$key => is_array($saved) && isset($saved[$key]) ? (string) $saved[$key] : null])->all();
    }

    /**
     * Fills unmapped keys with the account whose name matches the default.
     *
     * @param  list<array<string, mixed>>  $accounts  QuickBooks Account rows
     * @param  array<string, string|null>  $current
     * @return array<string, string|null>
     */
    public static function guess(array $accounts, array $current): array
    {
        foreach (self::KEYS as $key => [, $types, $default]) {
            if (($current[$key] ?? null) !== null) {
                continue;
            }

            foreach ($accounts as $account) {
                $name = strtolower((string) ($account['Name'] ?? ''));
                $type = (string) ($account['AccountType'] ?? '');

                if (in_array($type, $types, true) && ($name === strtolower($default) || ($key === 'undeposited' && in_array($name, ['undeposited funds', 'payments to deposit'], true)))) {
                    $current[$key] = (string) $account['Id'];
                    break;
                }
            }
        }

        return $current;
    }

    public static function complete(Integration $integration): bool
    {
        return ! in_array(null, self::for($integration), true);
    }
}
