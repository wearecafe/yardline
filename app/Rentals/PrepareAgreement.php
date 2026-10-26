<?php

namespace App\Rentals;

use App\Models\Rental;
use App\Models\RentalAgreement;
use App\Support\Money;

/**
 * Snapshots the terms and what the customer is agreeing to, so a later
 * change to the yard's terms never alters a contract already offered.
 * An unsigned agreement is refreshed whenever the rental changes.
 */
class PrepareAgreement
{
    public function handle(Rental $rental): RentalAgreement
    {
        $rental->loadMissing(['items.unit', 'tenant', 'customer', 'organization']);

        if ($rental->agreement?->signed_at !== null) {
            return $rental->agreement;
        }

        $settings = RentalSettings::for($rental->tenant);
        $tz = $rental->tenant->timezone;

        $summary = [
            'number' => $rental->number,
            'customer' => $rental->customerName(),
            'equipment' => $rental->items->map(fn ($item) => trim($item->description.($item->unit ? " · {$item->unit->serial}" : '')))->all(),
            'out' => $rental->starts_at->setTimezone($tz)->format('D M j, g:i a').($rental->fulfillment->value === 'delivery' ? ' · delivered' : ''),
            'due_back' => $rental->ends_at->setTimezone($tz)->format('D M j, g:i a'),
            'total' => Money::format($rental->total_cents),
            'deposit' => Money::format($rental->deposit_cents),
            'late_return' => $settings->lateFeePolicy,
            'damage_waiver' => $rental->damage_waiver
                ? 'Accepted, '.rtrim(rtrim((string) $rental->damage_waiver_percent, '0'), '.').'%'
                : 'Declined (customer’s own insurance)',
        ];

        $agreement = $rental->agreement ?? new RentalAgreement(['rental_id' => $rental->id]);
        $agreement->fill(['terms' => $settings->terms, 'summary' => $summary])->save();

        $rental->setRelation('agreement', $agreement);

        return $agreement;
    }
}
