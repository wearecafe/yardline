<?php

namespace App\Http\Controllers\Rentals;

use App\Enums\RentalUnitStatus;
use App\Http\Controllers\Controller;
use App\Models\RentalUnit;
use App\Rentals\Availability;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Availability: units down the side, two weeks across, bookings and shop
 * time as bars. Conflicts (an overdue unit with a booking coming up, a due
 * service under a reservation) are called out above.
 */
class CalendarController extends Controller
{
    private const DAYS = 14;

    public function __invoke(Request $request, Availability $availability): Response
    {
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->string('from')->toString(), $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfDay();
        $to = $from->addDays(self::DAYS);

        $units = RentalUnit::query()
            ->with('product')
            ->where('status', '!=', RentalUnitStatus::Retired->value)
            ->get()
            ->sortBy(fn (RentalUnit $unit) => [$unit->product->category, $unit->product->name, $unit->serial])
            ->values();

        $bars = $availability->calendar($units, $from->utc(), $to->utc());

        return Inertia::render('rentals/calendar', [
            'from' => $from->toDateString(),
            'days' => self::DAYS,
            'timezone' => $tz,
            'units' => $units->map(fn (RentalUnit $unit) => [
                'id' => $unit->id,
                'product_id' => $unit->product_id,
                'name' => $unit->product->name,
                'serial' => $unit->serial,
                'service_due' => $unit->serviceDue(),
                'bars' => $bars[$unit->id] ?? [],
            ]),
            'conflicts' => $this->conflicts($units, $bars, $tz),
        ]);
    }

    /**
     * @param  Collection<int, RentalUnit>  $units
     * @param  array<int, list<array<string, mixed>>>  $bars
     * @return list<array{unit_id: int, message: string, rental_id: int|null}>
     */
    private function conflicts($units, array $bars, string $tz): array
    {
        $conflicts = [];

        foreach ($units as $unit) {
            $unitBars = collect($bars[$unit->id] ?? []);
            $overdue = $unitBars->firstWhere('kind', 'overdue');
            $reserved = $unitBars->where('kind', 'reserved');

            if ($overdue !== null && $reserved->isNotEmpty()) {
                $next = $reserved->sortBy('starts_at')->first();
                $conflicts[] = [
                    'unit_id' => $unit->id,
                    'rental_id' => $next['rental_id'],
                    'message' => "{$unit->product->name} {$unit->serial} is overdue and reserved for {$next['label']} on "
                        .CarbonImmutable::parse($next['starts_at'])->setTimezone($tz)->format('M j').'.',
                ];
            }

            if (($unit->serviceDue() || $unitBars->contains('kind', 'service')) && $reserved->isNotEmpty()) {
                $next = $reserved->sortBy('starts_at')->first();
                $conflicts[] = [
                    'unit_id' => $unit->id,
                    'rental_id' => $next['rental_id'],
                    'message' => "{$unit->product->name} {$unit->serial} needs service but is reserved for {$next['label']}.",
                ];
            }
        }

        return $conflicts;
    }
}
