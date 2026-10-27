<?php

namespace App\Http\Controllers\Time;

use App\Enums\ClockState;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Time\Concerns\UsesTime;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use App\Time\TimeClock;
use App\Time\TimeRules;
use App\Time\TimeSettings;
use App\Time\TimesheetCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clock on the counter tablet: tap your name, enter your PIN, punch.
 * Runs on a device someone in the yard is signed in to.
 */
class KioskController extends Controller
{
    use UsesTime;

    private const PIN_TRIES = 5;

    public function show(Request $request, TimeClock $clock): Response
    {
        $settings = TimeSettings::current();
        $now = CarbonImmutable::now();

        $people = Employee::query()
            ->tracked()
            ->with('location:id,name')
            ->orderByName()
            ->get()
            ->map(function (Employee $e) use ($clock, $settings, $now) {
                $status = $clock->status($e, $now);
                $since = $status['state'] === ClockState::OnBreak ? $status['break_started_at'] : $status['shift_started_at'];

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'yard_id' => $e->location_id,
                    'has_pin' => $e->hasPin(),
                    'state' => $status['state']->value,
                    'since_label' => $since?->setTimezone($this->tz())->format('g:i a'),
                    'allowed' => array_map(fn (PunchType $t) => ['type' => $t->value, 'label' => $t->label()], $status['state']->allowed()),
                    'needs_photo' => $e->needsPhoto($settings),
                ];
            });

        return Inertia::render('kiosk/time-clock', [
            'people' => $people,
            'yards' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'business' => CurrentTenant::get()->name ?? '',
        ]);
    }

    public function punch(Request $request, TimeClock $clock): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', TenantRule::exists('employees')],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'type' => ['required', Rule::enum(PunchType::class)],
            'location_id' => ['nullable', 'integer', TenantRule::exists('locations')],
            'photo' => ['nullable', 'string', 'max:3000000'],
        ]);

        $employee = Employee::query()->tracked()->findOrFail((int) $data['employee_id']);
        $key = 'kiosk-pin:'.$employee->tenant_id.':'.$employee->id;

        if (RateLimiter::tooManyAttempts($key, self::PIN_TRIES)) {
            TimeRules::fail('Too many wrong PINs. Try again in a few minutes or ask a manager.', 'pin');
        }

        if (! $employee->checkPin((string) $data['pin'])) {
            RateLimiter::hit($key, 300);
            TimeRules::fail('That PIN doesn’t match.', 'pin');
        }

        RateLimiter::clear($key);

        $type = PunchType::from((string) $data['type']);
        $punch = $clock->punch($employee, $type, PunchSource::Kiosk, [
            'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : $employee->location_id,
            'photo' => $data['photo'] ?? null,
            'recorded_by' => $user->id,
        ]);

        $tz = $this->tz();
        $settings = TimeSettings::current();
        $today = CarbonImmutable::now($tz);
        $week = TimesheetCalculator::make()->forRange($employee, $settings->weekStart($today), $today)->totalMinutes();

        Inertia::flash('kiosk', [
            'name' => $employee->name,
            'action' => $type->label(),
            'time' => $punch->punched_at->setTimezone($tz)->format('g:i a'),
            'week' => TimesheetCalculator::hours($week).' this week',
            'overtime' => $employee->earnsOvertime() && $week > $settings->weeklyOvertimeHours * 60,
        ]);

        return back();
    }
}
