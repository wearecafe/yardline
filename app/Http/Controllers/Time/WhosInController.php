<?php

namespace App\Http\Controllers\Time;

use App\Enums\ClockState;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Time\Concerns\UsesTime;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Truck;
use App\Time\Geofence;
use App\Time\TimeClock;
use App\Time\TimeSettings;
use App\Time\TimesheetCalculator;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who's on the clock right now, by yard: working, on a break or out,
 * hours today and this week, and anyone close to overtime.
 */
class WhosInController extends Controller
{
    use UsesTime;

    /** Warn this many hours before the weekly overtime threshold. */
    private const OVERTIME_WARNING_HOURS = 4;

    public function index(TimeClock $clock): Response
    {
        $settings = TimeSettings::current();
        $tz = $this->tz();
        $now = CarbonImmutable::now();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $calculator = TimesheetCalculator::make();
        $threshold = $settings->weeklyOvertimeHours * 60;
        $trucks = Truck::query()->whereNotNull('driver_id')->get(['id', 'name', 'driver_id']);

        $rows = Employee::query()
            ->tracked()
            ->with('location:id,name', 'user:id,role')
            ->orderBy('name')
            ->get()
            ->map(function (Employee $e) use ($clock, $calculator, $settings, $today, $now, $tz, $threshold, $trucks) {
                $status = $clock->status($e, $now);
                $week = $calculator->forRange($e, $settings->weekStart($today), $today, $now);
                $day = $week->days === [] ? null : $week->days[array_key_last($week->days)];
                $since = $status['state'] === ClockState::OnBreak ? $status['break_started_at'] : $status['shift_started_at'];
                $last = $status['last'];
                $weekMinutes = $week->totalMinutes();

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'job_title' => $e->job_title ?? ($e->user?->isDriver() ? 'Driver' : null),
                    'yard_id' => $e->location_id,
                    'state' => $status['state']->value,
                    'state_label' => $status['state']->label(),
                    'since_label' => $since?->setTimezone($tz)->format('g:i a'),
                    'last_label' => $last !== null ? $last->type->short().' '.$last->punched_at->setTimezone($tz)->format('D g:i a') : null,
                    'where' => $last === null || $status['state'] === ClockState::Out ? null : match (true) {
                        $last->geofence === Geofence::OUTSIDE => 'Away from the yard',
                        $last->geofence === Geofence::TAKE_HOME => 'Take-home truck',
                        $last->geofence === Geofence::UNKNOWN => 'No location',
                        $last->location !== null => $last->location->name,
                        default => $last->source->label(),
                    },
                    'away' => $last?->geofence === Geofence::OUTSIDE && $status['state'] !== ClockState::Out,
                    'truck' => $trucks->firstWhere('driver_id', $e->user_id)?->name,
                    'today_minutes' => $day !== null ? $day->regularMinutes + $day->overtimeMinutes : 0,
                    'today_label' => TimesheetCalculator::hours($day !== null ? $day->regularMinutes + $day->overtimeMinutes : 0),
                    'week_minutes' => $weekMinutes,
                    'week_label' => TimesheetCalculator::hours($weekMinutes),
                    'overtime' => $e->earnsOvertime() && $weekMinutes > $threshold,
                    'near_overtime' => $e->earnsOvertime() && $weekMinutes <= $threshold && $weekMinutes >= $threshold - self::OVERTIME_WARNING_HOURS * 60,
                    'missed_out' => $status['missed_out']?->punched_at->setTimezone($tz)->format('D M j, g:i a'),
                    'exceptions' => $day === null ? [] : array_values(array_unique(array_column($day->exceptions, 'label'))),
                ];
            });

        return Inertia::render('time/board', [
            'rows' => $rows->values(),
            'yards' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'today' => $today->format('l, F j'),
            'overtime_after' => $settings->weeklyOvertimeHours,
            'counts' => [
                'working' => $rows->where('state', ClockState::Working->value)->count(),
                'on_break' => $rows->where('state', ClockState::OnBreak->value)->count(),
                'out' => $rows->where('state', ClockState::Out->value)->count(),
                'near_overtime' => $rows->where('near_overtime', true)->count() + $rows->where('overtime', true)->count(),
            ],
            'unset' => Employee::query()->tracked()->whereNull('pin_hash')->whereNull('user_id')->count(),
        ]);
    }
}
