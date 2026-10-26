<?php

namespace App\Rentals;

use App\Enums\RentalStatus;
use App\Fulfillment\RentalStops;
use App\Models\Rental;
use App\Rentals\Notifications\RentalNotifier;
use Carbon\CarbonImmutable;

/**
 * Turns a quote into a reservation: every item has a unit, every unit is
 * free and rentable for the dates, and tax could be calculated.
 */
class ReserveRental
{
    public function __construct(
        private readonly Availability $availability,
        private readonly RecalculateRental $recalculate,
        private readonly RentalNotifier $notifier,
    ) {}

    public function handle(Rental $rental): Rental
    {
        if ($rental->status !== RentalStatus::Quote) {
            RentalRules::fail('Only quotes can be reserved.');
        }

        $this->guardUnits($rental);

        $this->recalculate->handle($rental, requireTax: true);

        $rental->status = RentalStatus::Reserved;
        $rental->save();

        app(RentalStops::class)->sync($rental);

        $this->notifier->send($rental, 'confirmation');

        return $rental;
    }

    public function guardUnits(Rental $rental): void
    {
        $rental->loadMissing('items.unit.product');
        $start = CarbonImmutable::instance($rental->starts_at);
        $end = CarbonImmutable::instance($rental->ends_at);

        foreach ($rental->items as $item) {
            if ($item->unit === null) {
                RentalRules::fail("No {$item->description} is free for these dates. Pick other dates or another unit.", 'items');
            }

            $problem = $this->availability->problem($item->unit, $start, $end, $rental->id);

            if ($problem !== null) {
                RentalRules::fail($problem, 'items');
            }
        }
    }
}
