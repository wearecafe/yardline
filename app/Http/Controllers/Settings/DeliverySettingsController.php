<?php

namespace App\Http\Controllers\Settings;

use App\Fulfillment\DeliverySettings;
use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\Truck;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How deliveries are charged, the delivery zones, and the trucks on the
 * dispatch board.
 */
class DeliverySettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/delivery', [
            'settings' => DeliverySettings::current()->toArray(),
            'methods' => collect(DeliverySettings::METHODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'zones' => DeliveryZone::query()->orderBy('position')->get()->map(fn (DeliveryZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'postal_prefixes' => implode(', ', $zone->postal_prefixes ?? []),
                'fee' => number_format($zone->fee_cents / 100, 2, '.', ''),
            ]),
            'trucks' => Truck::query()->orderBy('position')->orderBy('name')->get(),
            'yards' => Location::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(DeliverySettings::METHODS))],
            'base' => ['required', 'numeric', 'min:0', 'max:99999'],
            'per_km' => ['required', 'numeric', 'min:0', 'max:9999'],
            'per_load' => ['required', 'numeric', 'min:0', 'max:99999'],
            'load_capacity_t' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'out_of_zone' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'eta_texts' => ['boolean'],
        ]);

        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        $settings = $tenant->settings ?? [];
        $settings['delivery'] = [
            'method' => $data['method'],
            'base_cents' => (int) Money::parse($data['base']),
            'per_km_cents' => (int) Money::parse($data['per_km']),
            'per_load_cents' => (int) Money::parse($data['per_load']),
            'load_capacity_kg' => (int) round((float) $data['load_capacity_t'] * 1000),
            'out_of_zone_cents' => (int) (Money::parse($data['out_of_zone'] ?? null) ?? 0),
            'eta_texts' => (bool) ($data['eta_texts'] ?? false),
        ];
        $tenant->update(['settings' => $settings]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Delivery settings saved.']);

        return back();
    }

    public function storeTruck(Request $request): RedirectResponse
    {
        Truck::query()->create($this->truck($request) + ['position' => (int) Truck::query()->max('position') + 1]);

        return back();
    }

    public function updateTruck(Request $request, Truck $truck): RedirectResponse
    {
        $truck->update($this->truck($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$truck->name} saved."]);

        return back();
    }

    public function destroyTruck(Truck $truck): RedirectResponse
    {
        $truck->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function truck(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(['material', 'equipment'])],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:30'],
            'capacity_t' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'has_boom' => ['boolean'],
            'is_active' => ['boolean'],
            'location_id' => ['nullable', TenantRule::exists('locations')],
        ]);

        $data['capacity_kg'] = isset($data['capacity_t']) ? (int) round((float) $data['capacity_t'] * 1000) : null;
        unset($data['capacity_t']);

        return $data;
    }
}
