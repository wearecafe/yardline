<?php

namespace App\Rentals;

use App\Enums\RentalStatus;
use App\Enums\RentalUnitStatus;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\RentalItem;
use App\Models\RentalUnit;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which units are free when. A unit is unavailable over a time range if it
 * is booked by another reserved or out rental, is in the shop, or has
 * service due (locked out until the service is logged).
 */
class Availability
{
    /**
     * Rental items holding this unit over the range.
     *
     * @return Collection<int, RentalItem>
     */
    public function bookings(RentalUnit $unit, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreRentalId = null): Collection
    {
        return RentalItem::query()
            ->with('rental')
            ->where('rental_unit_id', $unit->id)
            ->whereHas('rental', function ($query) use ($from, $to, $ignoreRentalId) {
                $query->overlapping($from, $to)
                    ->when($ignoreRentalId, fn ($query) => $query->whereKeyNot($ignoreRentalId));
            })
            ->get();
    }

    /**
     * Why a unit can't take this booking, or null if it can.
     */
    public function problem(RentalUnit $unit, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreRentalId = null): ?string
    {
        if ($unit->status === RentalUnitStatus::Retired) {
            return "{$unit->serial} is retired.";
        }

        if ($unit->status === RentalUnitStatus::InShop) {
            return "{$unit->serial} is in the shop.";
        }

        if ($unit->serviceDue()) {
            return "{$unit->serial} is due for service and locked out until it's logged.";
        }

        $booking = $this->bookings($unit, $from, $to, $ignoreRentalId)->first();

        if ($booking !== null) {
            $rental = $booking->rental;
            $who = $rental->customerName();

            return $rental->isOverdue()
                ? "{$unit->serial} is overdue from {$who} ({$rental->number})."
                : "{$unit->serial} is booked for {$who} ({$rental->number}) from {$rental->starts_at->setTimezone($this->timezone())->format('M j')}.";
        }

        return null;
    }

    public function isFree(RentalUnit $unit, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreRentalId = null): bool
    {
        return $this->problem($unit, $from, $to, $ignoreRentalId) === null;
    }

    /**
     * Units of this product free for the whole range.
     *
     * @return Collection<int, RentalUnit>
     */
    public function freeUnits(Product $product, CarbonImmutable $from, CarbonImmutable $to, ?int $locationId = null, ?int $ignoreRentalId = null): Collection
    {
        return RentalUnit::query()
            ->with('product')
            ->where('product_id', $product->id)
            ->where('status', RentalUnitStatus::Available->value)
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('serial')
            ->get()
            ->filter(fn (RentalUnit $unit) => $this->isFree($unit, $from, $to, $ignoreRentalId))
            ->values();
    }

    /**
     * The earliest time from `$from` at which some unit of this product is
     * free for `$hours` hours. Checks the next 60 days.
     */
    public function nextAvailable(Product $product, CarbonImmutable $from, int $hours = 24): ?CarbonImmutable
    {
        $units = RentalUnit::query()->with('product')
            ->where('product_id', $product->id)
            ->where('status', RentalUnitStatus::Available->value)
            ->get()
            ->reject(fn (RentalUnit $unit) => $unit->serviceDue());

        if ($units->isEmpty()) {
            return null;
        }

        $candidate = $from;
        $limit = $from->addDays(60);

        while ($candidate->lessThan($limit)) {
            $end = $candidate->addHours($hours);

            foreach ($units as $unit) {
                if ($this->bookings($unit, $candidate, $end)->isEmpty()) {
                    return $candidate;
                }
            }

            // Jump to the next day at opening time.
            $candidate = $candidate->addDay()->startOfDay()->addHours(7);
        }

        return null;
    }

    /**
     * Calendar bars for units over a date range: bookings and open shop work.
     *
     * @param  Collection<int, RentalUnit>  $units
     * @return array<int, list<array{kind: string, label: string, starts_at: string, ends_at: string, rental_id: int|null, rental_item_id: int|null, number: string|null, movable: bool}>>
     */
    public function calendar(Collection $units, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $bars = [];
        $ids = $units->pluck('id')->all();

        $items = RentalItem::query()
            ->with(['rental.organization', 'rental.customer'])
            ->whereIn('rental_unit_id', $ids)
            ->whereHas('rental', fn ($query) => $query->overlapping($from, $to))
            ->get();

        foreach ($items as $item) {
            $rental = $item->rental;
            $kind = $rental->isOverdue() ? 'overdue' : ($rental->status === RentalStatus::Out ? 'out' : 'reserved');
            $end = $rental->isOverdue() ? CarbonImmutable::now()->max($rental->ends_at) : $rental->ends_at;

            $bars[(int) $item->rental_unit_id][] = [
                'kind' => $kind,
                'label' => $rental->customerName().($kind === 'overdue' ? ' · overdue' : ''),
                'starts_at' => $rental->starts_at->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'rental_id' => $rental->id,
                'rental_item_id' => $item->id,
                'number' => $rental->number,
                'movable' => $rental->isEditable(),
            ];
        }

        $shop = MaintenanceRecord::query()
            ->whereIn('rental_unit_id', $ids)
            ->where('status', 'open')
            ->get();

        foreach ($shop as $record) {
            $bars[$record->rental_unit_id][] = [
                'kind' => 'service',
                'label' => $record->description,
                'starts_at' => $record->opened_at->toIso8601String(),
                'ends_at' => CarbonImmutable::now()->addDay()->max($record->opened_at->addDay())->toIso8601String(),
                'rental_id' => null,
                'rental_item_id' => null,
                'number' => null,
                'movable' => false,
            ];
        }

        return $bars;
    }

    private function timezone(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }
}
