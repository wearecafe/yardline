<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Time\EmployeeController;
use App\Models\Employee;
use App\Models\Location;
use App\Tenancy\CurrentTenant;
use App\Time\TimeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Time and attendance settings: turning the add-on on, the overtime and
 * break rules, pay periods, kiosk photos, where each yard is for phone
 * punches, and the ADP company code.
 */
class TimeSettingsController extends Controller
{
    public function edit(): Response
    {
        $options = fn (array $values) => collect($values)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values();

        return Inertia::render('settings/time', [
            'settings' => TimeSettings::current()->toArray(),
            'provinces' => $options(TimeSettings::PROVINCES),
            'defaults' => collect(array_keys(TimeSettings::PROVINCES))->mapWithKeys(fn (string $p) => [$p => TimeSettings::forProvince($p)->toArray()]),
            'frequencies' => $options(TimeSettings::FREQUENCIES),
            'photoModes' => $options(TimeSettings::PHOTO),
            'weekdays' => $options(TimeSettings::WEEKDAYS),
            'yards' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'latitude', 'longitude', 'geofence_m']),
            'tracked' => Employee::query()->tracked()->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'province' => ['required', Rule::in(array_keys(TimeSettings::PROVINCES))],
            'weekly_overtime_hours' => ['required', 'integer', 'between:1,80'],
            'meal_after_hours' => ['required', 'integer', 'between:1,12'],
            'meal_minutes' => ['required', 'integer', 'between:0,120'],
            'long_shift_hours' => ['required', 'integer', 'between:4,24'],
            'daily_rest_hours' => ['required', 'integer', 'between:0,24'],
            'check_daily_rest' => ['boolean'],
            'rounding_minutes' => ['required', 'integer', Rule::in([0, 5, 6, 10, 15])],
            'auto_meal_deduction' => ['boolean'],
            'frequency' => ['required', Rule::in(array_keys(TimeSettings::FREQUENCIES))],
            'anchor' => ['required', 'date_format:Y-m-d'],
            'week_starts_on' => ['required', 'integer', 'between:0,6'],
            'photo' => ['required', Rule::in(array_keys(TimeSettings::PHOTO))],
            'geofence_m' => ['required', 'integer', 'between:50,5000'],
            'adp_company_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]*$/'],
            'adp_batch_id' => ['nullable', 'string', 'max:8', 'regex:/^[A-Za-z0-9]*$/'],
            'yards' => ['array'],
            'yards.*.id' => ['required', 'integer'],
            'yards.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:yards.*.longitude'],
            'yards.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:yards.*.latitude'],
            'yards.*.geofence_m' => ['nullable', 'integer', 'between:50,5000'],
        ]);

        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 404);

        $wasEnabled = TimeSettings::for($tenant)->enabled;
        $settings = $tenant->settings ?? [];
        $settings['time'] = [
            ...collect($data)->except('yards')->all(),
            'enabled' => (bool) ($data['enabled'] ?? false),
            'check_daily_rest' => (bool) ($data['check_daily_rest'] ?? false),
            'auto_meal_deduction' => (bool) ($data['auto_meal_deduction'] ?? false),
            'overtime_rate' => 1.5,
            'adp_company_code' => strtoupper((string) ($data['adp_company_code'] ?? '')),
            'adp_batch_id' => strtoupper((string) ($data['adp_batch_id'] ?? '')) ?: 'YL',
        ];

        $added = DB::transaction(function () use ($tenant, $settings, $data, $wasEnabled) {
            $tenant->update(['settings' => $settings]);

            foreach ($data['yards'] ?? [] as $yard) {
                Location::query()->whereKey((int) $yard['id'])->update([
                    'latitude' => $yard['latitude'] ?? null,
                    'longitude' => $yard['longitude'] ?? null,
                    'geofence_m' => $yard['geofence_m'] ?? null,
                ]);
            }

            // Turning it on for the first time sets up everyone who signs in.
            return ! $wasEnabled && ($settings['time']['enabled'] ?? false) && ! Employee::query()->exists()
                ? EmployeeController::provision()
                : 0;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $added > 0
            ? "Time and attendance is on. Added {$added} people; give the yard hands a PIN under Team → Employees."
            : 'Time settings saved.']);

        return back();
    }
}
