<?php

namespace App\Rentals;

use App\Enums\MaintenanceKind;
use App\Enums\RentalUnitStatus;
use App\Models\MaintenanceRecord;
use App\Models\RentalUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shop work on rental units. A unit with open shop work is "in shop" and
 * can't be booked; logging a service resets its service counters.
 */
class Maintenance
{
    public function open(RentalUnit $unit, MaintenanceKind $kind, string $description, User $user): MaintenanceRecord
    {
        return DB::transaction(function () use ($unit, $kind, $description, $user) {
            $record = $unit->maintenance()->create([
                'kind' => $kind,
                'description' => $description,
                'hour_meter' => $unit->hour_meter,
                'user_id' => $user->id,
                'opened_at' => now(),
            ]);

            $unit->forceFill(['status' => RentalUnitStatus::InShop])->save();

            return $record;
        });
    }

    public function complete(MaintenanceRecord $record, int $costCents, ?float $hourMeter, ?string $notes, User $user): MaintenanceRecord
    {
        return DB::transaction(function () use ($record, $costCents, $hourMeter, $notes, $user) {
            $unit = $record->unit;

            $record->forceFill([
                'status' => 'done',
                'cost_cents' => $costCents,
                'hour_meter' => $hourMeter ?? $unit->hour_meter,
                'description' => $notes ? $record->description.'. '.$notes : $record->description,
                'completed_at' => now(),
                'user_id' => $user->id,
            ])->save();

            $this->afterWork($unit, $record);

            return $record;
        });
    }

    /**
     * Log a service done without opening a shop ticket first.
     */
    public function logService(RentalUnit $unit, string $description, ?float $hourMeter, int $costCents, User $user): MaintenanceRecord
    {
        return DB::transaction(function () use ($unit, $description, $hourMeter, $costCents, $user) {
            $record = $unit->maintenance()->create([
                'kind' => MaintenanceKind::Service,
                'status' => 'done',
                'description' => $description,
                'hour_meter' => $hourMeter ?? $unit->hour_meter,
                'cost_cents' => $costCents,
                'user_id' => $user->id,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);

            $this->afterWork($unit, $record);

            return $record;
        });
    }

    private function afterWork(RentalUnit $unit, MaintenanceRecord $record): void
    {
        $updates = [];

        if ($record->kind === MaintenanceKind::Service) {
            $updates['last_service_hours'] = $record->hour_meter ?? $unit->hour_meter;
            $updates['last_service_on'] = now()->toDateString();
        }

        if ($record->hour_meter !== null) {
            $updates['hour_meter'] = $record->hour_meter;
        }

        $stillOpen = $unit->maintenance()->where('status', 'open')->whereKeyNot($record->id)->exists();
        if (! $stillOpen && $unit->status === RentalUnitStatus::InShop) {
            $updates['status'] = RentalUnitStatus::Available;
        }

        $unit->forceFill($updates)->save();
    }
}
