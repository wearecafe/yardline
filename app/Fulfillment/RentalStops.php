<?php

namespace App\Fulfillment;

use App\Enums\FulfillmentMethod;
use App\Enums\RentalStatus;
use App\Enums\StopStatus;
use App\Models\Rental;
use App\Models\Stop;
use Carbon\CarbonImmutable;

/**
 * Delivered rentals put two runs on the dispatch board: the drop-off on
 * the start date and the pickup on the return date.
 */
class RentalStops
{
    public function sync(Rental $rental): void
    {
        $stops = Stop::query()->where('stoppable_type', 'rental')->where('stoppable_id', $rental->id)->get()->keyBy('kind');
        $wanted = $rental->fulfillment === FulfillmentMethod::Delivery
            && in_array($rental->status, [RentalStatus::Reserved, RentalStatus::Out], true);

        if (! $wanted) {
            foreach ($stops as $stop) {
                if (! in_array($stop->status, [StopStatus::Done, StopStatus::EnRoute], true)) {
                    $stop->forceFill(['status' => StopStatus::Cancelled])->save();
                }
            }

            return;
        }

        $tz = $rental->tenant->timezone ?? 'America/Toronto';
        $units = $rental->items()->pluck('description')->unique()->implode(', ');

        foreach (['rental_drop' => $rental->starts_at, 'rental_pickup' => $rental->ends_at] as $kind => $at) {
            $local = CarbonImmutable::instance($at)->setTimezone($tz);
            $stop = $stops->get($kind) ?? new Stop([
                'stoppable_type' => 'rental',
                'stoppable_id' => $rental->id,
                'kind' => $kind,
                'duration_minutes' => 60,
            ]);

            if (in_array($stop->status, [StopStatus::Done, StopStatus::EnRoute], true)) {
                continue;
            }

            // Moving the rental moves its unscheduled runs; scheduled ones keep their slot unless the day changed.
            $dayChanged = $stop->date === null || $stop->date->toDateString() !== $local->toDateString();

            $stop->fill([
                'address' => $rental->delivery_address,
                'needs' => ($kind === 'rental_drop' ? 'Drop by ' : 'Pick up after ').$local->format('g a').($units ? " · {$units}" : ''),
            ]);

            if ($dayChanged) {
                $stop->fill(['date' => $local->toDateString(), 'starts_at' => null, 'truck_id' => null, 'status' => StopStatus::Unscheduled]);
            } elseif ($stop->status === StopStatus::Cancelled) {
                $stop->status = StopStatus::Unscheduled;
            }

            $stop->save();
        }
    }
}
