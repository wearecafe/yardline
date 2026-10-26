<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Rentals\RentalSettings;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rental policy (damage waiver, free Sunday, late returns, terms, hours)
 * and delivery zones, shared by rentals and goods deliveries.
 */
class RentalSettingsController extends Controller
{
    public function edit(): Response
    {
        $tenant = CurrentTenant::get();

        return Inertia::render('settings/rentals', [
            'settings' => RentalSettings::for($tenant)->toArray(),
            'zones' => DeliveryZone::query()->orderBy('position')->get()->map(fn (DeliveryZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'postal_prefixes' => implode(', ', $zone->postal_prefixes ?? []),
                'fee' => number_format($zone->fee_cents / 100, 2, '.', ''),
            ]),
            'bookingUrl' => $tenant ? route('book.show', $tenant->slug) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'damage_waiver_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'free_sunday_with_saturday' => ['boolean'],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'late_fee_policy' => ['required', 'string', 'max:500'],
            'terms' => ['required', 'string', 'max:50000'],
            'reminder_hours_before_return' => ['required', 'integer', 'min:1', 'max:168'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i', 'after:opening_time'],
        ]);

        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        $settings = $tenant->settings ?? [];
        $settings['rentals'] = [
            ...$data,
            'damage_waiver_percent' => (float) $data['damage_waiver_percent'],
            'free_sunday_with_saturday' => (bool) ($data['free_sunday_with_saturday'] ?? false),
        ];
        $tenant->update(['settings' => $settings]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rental settings saved.']);

        return back();
    }

    public function storeZone(Request $request): RedirectResponse
    {
        DeliveryZone::query()->create($this->zone($request) + ['position' => (int) DeliveryZone::query()->max('position') + 1]);

        return back();
    }

    public function updateZone(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $zone->update($this->zone($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$zone->name} saved."]);

        return back();
    }

    public function destroyZone(DeliveryZone $zone): RedirectResponse
    {
        $zone->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function zone(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'postal_prefixes' => ['nullable', 'string', 'max:2000'],
            'fee' => ['required', 'numeric', 'min:0', 'max:99999'],
        ]);

        return [
            'name' => $data['name'],
            'postal_prefixes' => array_values(array_filter(array_map(
                fn (string $prefix) => strtoupper(str_replace(' ', '', trim($prefix))),
                preg_split('/[,\s]+/', (string) ($data['postal_prefixes'] ?? '')) ?: [],
            ))),
            'fee_cents' => (int) Money::parse($data['fee']),
        ];
    }
}
