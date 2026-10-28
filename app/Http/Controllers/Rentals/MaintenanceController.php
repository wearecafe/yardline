<?php

namespace App\Http\Controllers\Rentals;

use App\Access\YardScope;
use App\Enums\MaintenanceKind;
use App\Enums\RentalUnitStatus;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRecord;
use App\Models\RentalUnit;
use App\Rentals\Maintenance;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $units = YardScope::apply(RentalUnit::query(), $user)
            ->with('product')
            ->where('status', '!=', RentalUnitStatus::Retired->value)
            ->get();

        $due = $units
            ->filter(fn (RentalUnit $unit) => $unit->serviceDue() || (($left = $unit->hoursUntilService()) !== null && $left <= 25)
                || (($date = $unit->serviceDueOn()) !== null && $date->lessThan(now()->addDays(14))))
            ->sortBy(fn (RentalUnit $unit) => $unit->hoursUntilService() ?? 9999)
            ->values()
            ->map(fn (RentalUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->product->name,
                'serial' => $unit->serial,
                'hour_meter' => $unit->hour_meter,
                'hours_until' => $unit->hoursUntilService(),
                'due_on' => $unit->serviceDueOn()?->toDateString(),
                'overdue' => $unit->serviceDue(),
            ]);

        $records = MaintenanceRecord::query()
            ->whereIn('rental_unit_id', YardScope::apply(RentalUnit::query(), $user)->select('id'))
            ->with(['unit.product', 'user:id,name'])
            ->latest('opened_at')
            ->limit(100)
            ->get();

        return Inertia::render('rentals/maintenance', [
            'due' => $due,
            'open' => $records->where('status', 'open')->values()->map(fn ($record) => $this->record($record)),
            'recent' => $records->where('status', 'done')->take(30)->values()->map(fn ($record) => $this->record($record)),
            'units' => $units->sortBy('serial')->values()->map(fn (RentalUnit $unit) => [
                'id' => $unit->id,
                'label' => "{$unit->serial} · {$unit->product->name}",
                'hour_meter' => $unit->hour_meter,
            ]),
        ]);
    }

    public function store(Request $request, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'rental_unit_id' => ['required', YardScope::existsIn('rental_units', $request->user())],
            'kind' => ['required', Rule::enum(MaintenanceKind::class)],
            'description' => ['required', 'string', 'max:255'],
            'done' => ['boolean'],
            'hour_meter' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $unit = RentalUnit::query()->findOrFail((int) $data['rental_unit_id']);

        if (($data['done'] ?? false) && $data['kind'] === MaintenanceKind::Service->value) {
            $maintenance->logService($unit, $data['description'], isset($data['hour_meter']) ? (float) $data['hour_meter'] : null, (int) (Money::parse($data['cost'] ?? null) ?? 0), $request->user());
            $message = "Service logged for {$unit->serial}.";
        } else {
            $maintenance->open($unit, MaintenanceKind::from($data['kind']), $data['description'], $request->user());
            $message = "{$unit->serial} is in the shop.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function complete(Request $request, MaintenanceRecord $record, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'hour_meter' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $maintenance->complete($record, (int) (Money::parse($data['cost'] ?? null) ?? 0), isset($data['hour_meter']) ? (float) $data['hour_meter'] : null, $data['notes'] ?? null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$record->unit->serial} is back in the fleet."]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function record(MaintenanceRecord $record): array
    {
        return [
            ...$record->only('id', 'kind', 'status', 'description', 'hour_meter', 'cost_cents'),
            'unit_id' => $record->rental_unit_id,
            'serial' => $record->unit->serial,
            'name' => $record->unit->product->name,
            'opened_at' => $record->opened_at->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),
            'user' => $record->user?->name,
        ];
    }
}
