<?php

namespace App\Rentals;

use App\Enums\InspectionKind;
use App\Models\Rental;
use App\Models\RentalInspection;
use App\Models\RentalItem;
use App\Models\User;
use App\Support\Files;

class RecordInspection
{
    public function handle(Rental $rental, RentalItem $item, InspectionKind $kind, InspectionData $data, ?User $user): RentalInspection
    {
        if ($item->rental_unit_id === null) {
            RentalRules::fail("{$item->description} has no unit assigned.");
        }

        $inspection = $rental->inspections()->create([
            'rental_unit_id' => $item->rental_unit_id,
            'kind' => $kind,
            'hour_meter' => $data->hourMeter,
            'fuel' => $data->fuel,
            'clean' => $data->clean,
            'notes' => $data->notes,
            'user_id' => $user?->id,
        ]);

        foreach ($data->photos as $photo) {
            $inspection->photos()->create([
                'path' => Files::storeUpload($photo['file'], 'inspections'),
                'label' => $photo['label'],
                'new_damage' => $photo['new_damage'],
            ]);
        }

        if ($data->hourMeter !== null && $item->unit !== null) {
            $item->unit->forceFill(['hour_meter' => $data->hourMeter])->save();
        }

        return $inspection;
    }
}
