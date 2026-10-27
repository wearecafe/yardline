<?php

namespace App\Billing;

/**
 * The item changes that turn a Stripe subscription's items into what the
 * tenant should be paying for. Items whose price isn't one of ours (added
 * by hand in Stripe) are left alone.
 */
final class ItemChanges
{
    /**
     * @param  list<array{id: string, price: string, key: string|null, quantity: int}>  $current
     * @param  array<string, int>  $desired  quantity by item key
     * @param  array<string, string>  $prices  Stripe price ID by item key
     * @return list<array<string, mixed>> for the `items` parameter of a subscription update
     */
    public static function for(array $current, array $desired, array $prices): array
    {
        $changes = [];
        $seen = [];

        foreach ($current as $item) {
            $key = $item['key'];

            if ($key === null) {
                continue;
            }

            if (! isset($desired[$key]) || isset($seen[$key])) {
                $changes[] = ['id' => $item['id'], 'deleted' => true];

                continue;
            }

            $seen[$key] = true;
            $change = ['id' => $item['id']];

            if ($item['price'] !== $prices[$key]) {
                $change['price'] = $prices[$key]; // the amount was changed in config
            }

            if ($item['quantity'] !== $desired[$key]) {
                $change['quantity'] = $desired[$key];
            }

            if (count($change) > 1) {
                $changes[] = $change;
            }
        }

        foreach ($desired as $key => $quantity) {
            if (! isset($seen[$key])) {
                $changes[] = ['price' => $prices[$key], 'quantity' => $quantity];
            }
        }

        return $changes;
    }
}
