<?php

namespace App\Http\Controllers\Time;

use App\Enums\ClockState;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Time\Concerns\UsesTime;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\TimeAdjustment;
use App\Models\Timesheet;
use App\Models\User;
use App\Time\PunchCorrections;
use App\Time\TimeClock;
use App\Time\TimeRules;
use App\Time\Timesheets;
use App\Time\TimesheetCalculator;
use App\Time\TimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everyone's own clock and timesheet, on their phone or computer.
 * Drivers get it inside the driver app.
 */
class MyTimeController extends Controller
{
    use UsesTime;

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $page = $user->isDriver() ? 'driver/time' : 'time/me';
        $employee = Employee::forUser($user);

        if ($employee === null || ! $employee->tracks_time) {
            return Inertia::render($page, ['employee' => null]);
        }

        $period = $this->period($request);
        $settings = TimeSettings::current();
        $calculator = TimesheetCalculator::make();
        $result = $calculator->forPeriod($employee, $period);
        $status = (new TimeClock($settings))->status($employee);
        $sheet = Timesheet::query()->where('pay_period_id', $period->id)->where('employee_id', $employee->id)->first();
        $today = CarbonImmutable::now($this->tz());
        $week = $calculator->forRange($employee, $settings->weekStart($today), $today);
        $presenter = $this->presenter();

        return Inertia::render($page, [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'take_home_truck' => $employee->take_home_truck,
                'earns_overtime' => $employee->earnsOvertime(),
            ],
            'clock' => [
                'state' => $status['state']->value,
                'state_label' => $status['state']->label(),
                'allowed' => array_map(fn (PunchType $t) => ['type' => $t->value, 'label' => $t->label()], $status['state']->allowed()),
                'since' => ($status['state'] === ClockState::OnBreak ? $status['break_started_at'] : $status['shift_started_at'])?->toIso8601String(),
                'since_label' => ($status['state'] === ClockState::OnBreak ? $status['break_started_at'] : $status['shift_started_at'])?->setTimezone($this->tz())->format('g:i a'),
                'missed_out' => $status['missed_out']?->punched_at->setTimezone($this->tz())->format('D M j, g:i a'),
                'week_minutes' => $week->totalMinutes(),
                'week_label' => TimesheetCalculator::hours($week->totalMinutes()),
                'overtime_after' => $settings->weeklyOvertimeHours,
                'wants_location' => true,
            ],
            'period' => $this->periodProps($period),
            'timesheet' => [
                'status' => $sheet?->status->value ?? 'open',
                'status_label' => $sheet?->status->label() ?? 'Open',
                'submitted_at' => $sheet?->submitted_at?->setTimezone($this->tz())->format('M j, g:i a'),
                'approved_at' => $sheet?->approved_at?->setTimezone($this->tz())->format('M j, g:i a'),
            ],
            ...$presenter->result($result),
        ]);
    }

    public function punch(Request $request, TimeClock $clock): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = Employee::forUser($user);

        if ($employee === null || ! $employee->tracks_time) {
            TimeRules::fail('You aren’t set up to clock in. Ask the office to add you under Team → Employees.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::enum(PunchType::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // Set when the phone queued the punch while offline.
            'device_at' => ['nullable', 'date'],
        ]);

        $type = PunchType::from((string) $data['type']);
        $punch = $clock->punch($employee, $type, $user->isDriver() ? PunchSource::Driver : PunchSource::Mobile, [
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'accuracy_m' => isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
            'device_at' => isset($data['device_at']) ? CarbonImmutable::parse((string) $data['device_at']) : null,
            'recorded_by' => $user->id,
        ]);

        $at = $punch->punched_at->setTimezone($this->tz())->format('g:i a');

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($type) {
            PunchType::In => "Clocked in at {$at}.",
            PunchType::BreakStart => "Break started at {$at}.",
            PunchType::BreakEnd => "Back from break at {$at}.",
            PunchType::Out => "Clocked out at {$at}.",
        }]);

        return back();
    }

    public function submit(Request $request, Timesheets $timesheets): RedirectResponse
    {
        $employee = $this->me($request);
        $data = $request->validate(['period_id' => ['required', 'integer']]);
        $period = PayPeriod::query()->findOrFail((int) $data['period_id']);

        $timesheets->submit($employee, $period);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Timesheet sent for approval.']);

        return back();
    }

    public function dispute(Request $request, TimeAdjustment $adjustment, PunchCorrections $corrections): RedirectResponse
    {
        $employee = $this->me($request);
        abort_unless($adjustment->employee_id === $employee->id, 404);

        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $corrections->dispute($adjustment, (string) $data['note']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to your manager to look at again.']);

        return back();
    }

    private function me(Request $request): Employee
    {
        /** @var User $user */
        $user = $request->user();
        $employee = Employee::forUser($user);
        abort_if($employee === null, 404);

        return $employee;
    }
}
