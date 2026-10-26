<?php

namespace App\Http\Controllers\Rentals;

use App\Enums\RentalStatus;
use App\Enums\RentalUnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\RentalUnit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The rental fleet: every unit with its status, who has it, hours and
 * next service.
 */
class FleetController extends Controller
{
    public function index(): Response
    {
        $now = CarbonImmutable::now();
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';

        $units = RentalUnit::query()
            ->with(['product', 'location:id,name'])
            ->where('status', '!=', RentalUnitStatus::Retired->value)
            ->get()
            ->sortBy(fn (RentalUnit $unit) => [$unit->product->category, $unit->product->name, $unit->serial])
            ->values();

        // Current and next booking per unit.
        $bookings = RentalItem::query()
            ->with('rental.organization:id,name', 'rental.customer:id,first_name,last_name')
            ->whereIn('rental_unit_id', $units->pluck('id'))
            ->whereHas('rental', fn ($query) => $query->whereIn('status', RentalStatus::booking())
                ->where(fn ($query) => $query->where('ends_at', '>', $now)->orWhere('status', RentalStatus::Out->value)))
            ->get()
            ->sortBy(fn (RentalItem $item) => $item->rental->starts_at)
            ->groupBy('rental_unit_id');

        $rows = $units->map(function (RentalUnit $unit) use ($bookings, $tz) {
            /** @var RentalItem|null $booking */
            $booking = $bookings->get($unit->id)?->first();
            $rental = $booking?->rental;

            [$status, $with] = match (true) {
                $unit->status === RentalUnitStatus::InShop => ['in_shop', $unit->maintenance()->where('status', 'open')->value('description') ?? 'In the shop'],
                $rental?->isOverdue() === true => ['overdue', $rental->customerName().' · was due '.$rental->ends_at->setTimezone($tz)->format('M j')],
                $rental?->status === RentalStatus::Out => ['on_rent', $rental->customerName().' · '.$this->due($rental->ends_at->setTimezone($tz))],
                $rental?->status === RentalStatus::Reserved => ['reserved', $rental->customerName().' · from '.$rental->starts_at->setTimezone($tz)->format('M j')],
                $unit->serviceDue() => ['service_due', 'Locked out until serviced'],
                default => ['available', null],
            };

            $hoursLeft = $unit->hoursUntilService();
            $dueOn = $unit->serviceDueOn();
            $service = match (true) {
                $hoursLeft !== null && $unit->serviceIntervalHours() !== null => (int) $unit->serviceIntervalHours().'-hr at '.number_format((float) ($unit->last_service_hours ?? 0) + (int) $unit->serviceIntervalHours()).' · '.rtrim(rtrim(number_format(max(0, $hoursLeft), 1), '0'), '.').' hrs',
                $dueOn !== null => $dueOn->setTimezone($tz)->format('M j'),
                default => null,
            };

            return [
                'id' => $unit->id,
                'name' => $unit->product->name,
                'category' => $unit->product->category,
                'serial' => $unit->serial,
                'status' => $status,
                'with' => $with,
                'rental_id' => $rental?->id,
                'hours' => $unit->hour_meter,
                'service' => $service,
                'service_soon' => $hoursLeft !== null && $hoursLeft <= 10,
                'day_rate_cents' => $unit->product->price_cents,
                'location' => $unit->location->name,
            ];
        });

        $todayEnd = CarbonImmutable::now($tz)->endOfDay();

        return Inertia::render('rentals/fleet', [
            'units' => $rows,
            'stats' => [
                'total' => $rows->count(),
                'on_rent' => $rows->whereIn('status', ['on_rent', 'overdue'])->count(),
                'in_shop' => $rows->where('status', 'in_shop')->count(),
                'utilization' => $this->utilization($units),
                'due_today' => Rental::query()->where('status', RentalStatus::Out->value)
                    ->whereBetween('ends_at', [$now, $todayEnd])->count(),
                'overdue' => Rental::query()->where('status', RentalStatus::Out->value)->where('ends_at', '<', $now)->count(),
                'service_soon' => $units->filter(fn (RentalUnit $unit) => ($left = $unit->hoursUntilService()) !== null && $left <= 10)->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('rentals/unit-form', [
            'unit' => null,
            ...$this->options(),
            'productId' => $request->integer('product_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unit = RentalUnit::query()->create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$unit->serial} added to the fleet."]);

        return to_route('fleet.units.show', $unit);
    }

    /**
     * One unit's history: every rental, inspection and repair, with what it
     * has earned and cost.
     */
    public function show(RentalUnit $unit): Response
    {
        $unit->load(['product', 'location', 'maintenance.user:id,name']);
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';

        $items = RentalItem::query()
            ->with('rental.organization:id,name', 'rental.customer:id,first_name,last_name')
            ->where('rental_unit_id', $unit->id)
            ->whereHas('rental', fn ($query) => $query->where('status', '!=', RentalStatus::Cancelled->value))
            ->get()
            ->sortByDesc(fn (RentalItem $item) => $item->rental->starts_at)
            ->values();

        return Inertia::render('rentals/unit', [
            'unit' => [
                ...$unit->only('id', 'serial', 'status', 'hour_meter', 'notes', 'purchase_cost_cents', 'location_id', 'service_interval_hours', 'service_interval_days'),
                'name' => $unit->product->name,
                'product_id' => $unit->product_id,
                'location' => $unit->location->name,
                'purchased_on' => $unit->purchased_on?->toDateString(),
                'hours_until_service' => $unit->hoursUntilService(),
                'service_due_on' => $unit->serviceDueOn()?->toDateString(),
                'service_due' => $unit->serviceDue(),
            ],
            'rentals' => $items->map(fn (RentalItem $item) => [
                'id' => $item->rental->id,
                'number' => $item->rental->number,
                'status' => $item->rental->displayStatus(),
                'customer' => $item->rental->customerName(),
                'starts_at' => $item->rental->starts_at->toIso8601String(),
                'ends_at' => ($item->rental->returned_at ?? $item->rental->ends_at)->toIso8601String(),
                'charge_cents' => $item->charge_cents,
                'hours' => $item->hours_in !== null && $item->hours_out !== null ? round((float) $item->hours_in - (float) $item->hours_out, 1) : null,
            ]),
            'maintenance' => $unit->maintenance->sortByDesc('opened_at')->values()->map(fn ($record) => [
                ...$record->only('id', 'kind', 'status', 'description', 'hour_meter', 'cost_cents'),
                'opened_at' => $record->opened_at->toIso8601String(),
                'completed_at' => $record->completed_at?->toIso8601String(),
                'user' => $record->user?->name,
            ]),
            'totals' => [
                'revenue' => Money::format((int) $items->sum('charge_cents')),
                'maintenance' => Money::format((int) $unit->maintenance->sum('cost_cents')),
                'rentals' => $items->count(),
            ],
            'timezone' => $tz,
            ...$this->options(),
        ]);
    }

    public function update(Request $request, RentalUnit $unit): RedirectResponse
    {
        $unit->update($this->validated($request, $unit));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$unit->serial} saved."]);

        return back();
    }

    /**
     * Share of unit-days on rent over the last 30 days.
     *
     * @param  Collection<int, RentalUnit>  $units
     */
    private function utilization($units): int
    {
        if ($units->isEmpty()) {
            return 0;
        }

        $from = CarbonImmutable::now()->subDays(30);
        $to = CarbonImmutable::now();
        $hours = 0.0;

        $items = RentalItem::query()
            ->with('rental')
            ->whereIn('rental_unit_id', $units->pluck('id'))
            ->whereHas('rental', fn ($query) => $query
                ->whereIn('status', [RentalStatus::Out->value, RentalStatus::Returned->value])
                ->where('starts_at', '<', $to)
                ->where(fn ($query) => $query->where('returned_at', '>', $from)->orWhereNull('returned_at')))
            ->get();

        foreach ($items as $item) {
            $start = CarbonImmutable::instance($item->rental->checked_out_at ?? $item->rental->starts_at)->max($from);
            $end = CarbonImmutable::instance($item->rental->returned_at ?? $to)->min($to);
            $hours += max(0, $start->diffInHours($end, false));
        }

        return (int) round(100 * $hours / ($units->count() * 30 * 24));
    }

    private function due(CarbonImmutable $when): string
    {
        return match (true) {
            $when->isToday() => 'today '.$when->format('g a'),
            $when->isTomorrow() => 'tomorrow '.$when->format('g a'),
            default => $when->format('M j'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?RentalUnit $unit = null): array
    {
        $data = $request->validate([
            'product_id' => ['required', TenantRule::exists('products')->where('kind', 'rental')],
            'location_id' => ['required', TenantRule::exists('locations')],
            'serial' => ['required', 'string', 'max:40', TenantRule::unique('rental_units', 'serial', $unit?->id)],
            'status' => ['required', Rule::enum(RentalUnitStatus::class)],
            'hour_meter' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'service_interval_hours' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'service_interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'last_service_hours' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'last_service_on' => ['nullable', 'date'],
            'purchased_on' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['serial'] = strtoupper($data['serial']);
        $data['purchase_cost_cents'] = Money::parse($data['purchase_cost'] ?? null);
        unset($data['purchase_cost']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'products' => Product::query()->where('kind', 'rental')->orderBy('name')->get(['id', 'name', 'service_interval_hours', 'service_interval_days']),
            'locations' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
