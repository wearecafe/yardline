<?php

namespace App\Accounting\QuickBooks;

use App\Models\Integration;

/**
 * The QuickBooks sales tax code used for each province YardRunner taxes in.
 * Stripe Tax decides the amount; the code decides which line of the HST or
 * QST return it's filed on.
 */
final class TaxCodes
{
    /**
     * key => [label, names to look for]
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const KEYS = [
        'ON' => ['Ontario sales (HST)', ['HST ON']],
        'QC' => ['Quebec sales (GST and QST)', ['GST/QST QC', 'GST/QST']],
        'other' => ['Other provinces', ['GST']],
        'exempt' => ['Exempt sales and deposits', ['Exempt']],
    ];

    /**
     * @return array<string, string|null>
     */
    public static function for(Integration $integration): array
    {
        $saved = $integration->settings['tax_codes'] ?? [];

        return collect(self::KEYS)->mapWithKeys(fn ($def, $key) => [$key => is_array($saved) && isset($saved[$key]) ? (string) $saved[$key] : null])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $codes  QuickBooks TaxCode rows
     * @param  array<string, string|null>  $current
     * @return array<string, string|null>
     */
    public static function guess(array $codes, array $current): array
    {
        foreach (self::KEYS as $key => [, $names]) {
            if (($current[$key] ?? null) !== null) {
                continue;
            }

            foreach ($names as $name) {
                foreach ($codes as $code) {
                    if (strcasecmp(trim((string) ($code['Name'] ?? '')), $name) === 0) {
                        $current[$key] = (string) $code['Id'];
                        break 2;
                    }
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
