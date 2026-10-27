<?php

namespace App\Http\Controllers\Time;

use App\Accounting\QuickBooks\QuickBooks;
use App\Enums\PunchType;
use App\Enums\TimesheetStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Time\Concerns\UsesTime;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollExport;
use App\Models\Timesheet;
use App\Models\User;
use App\Time\PayrollExporter;
use App\Time\TimesheetCalculator;
use App\Time\Timesheets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Timesheet review: the pay period's grid with exceptions first, one
 * person's days with their punches and corrections, and approval.
 */
class TimesheetController extends Controller
{
    use UsesTime;

    public function index(Request $request, Timesheets $timesheets): Response
    {
        $period = $this->period($request);
        $calculator = TimesheetCalculator::make();
        $sheets = Timesheet::query()->with('approver:id,name')->where('pay_period_id', $period->id)->get()->keyBy('employee_id');

        $rows = $timesheets->people($period)->map(function (Employee $e) use ($period, $calculator, $sheets, $timesheets) {
            /** @var Timesheet|null $sheet */
            $sheet = $sheets->get($e->id);
            $result = $calculator->forPeriod($e, $period);
            $approved = $sheet !== null && $sheet->isApproved();
            $regular = $sheet !== null && $approved ? (int) $sheet->regular_minutes : $result->regularMinutes;
            $overtime = $sheet !== null && $approved ? (int) $sheet->overtime_minutes : $result->overtimeMinutes;
            $exceptions = $result->exceptions();

            return [
                'id' => $e->id,
                'name' => $e->name,
                'job_title' => $e->job_title,
                'yard' => $e->location?->name,
                'archived' => $e->archived_at !== null,
                'regular' => $regular,
                'overtime' => $overtime,
                'regular_label' => TimesheetCalculator::hours($regular),
                'overtime_label' => TimesheetCalculator::hours($overtime),
                'total_label' => TimesheetCalculator::hours($regular + $overtime),
                'breaks_label' => TimesheetCalculator::hours($result->breakMinutes),
                'exceptions' => count($exceptions),
                'exception_labels' => array_values(array_unique(array_column($exceptions, 'label'))),
                'blocker' => $approved ? null : $timesheets->blocker($e, $result),
                'open' => $result->open,
                'status' => $sheet?->status->value ?? TimesheetStatus::Open->value,
                'status_label' => $sheet?->status->label() ?? TimesheetStatus::Open->label(),
                'approved_by' => $sheet?->approver?->name,
                'has_hours' => $regular + $overtime > 0 || count($exceptions) > 0,
            ];
        })
            // Exceptions first, then people waiting, then the rest.
            ->sortBy(fn (array $r) => [$r['exceptions'] > 0 ? 0 : 1, $r['status'] === 'approved' ? 1 : 0, $r['has_hours'] ? 0 : 1, $r['name']])
            ->values();

        $totals = [
            'regular' => TimesheetCalculator::hours((int) $rows->sum('regular')),
            'overtime' => TimesheetCalculator::hours((int) $rows->sum('overtime')),
            'people' => $rows->where('has_hours', true)->count(),
            'approved' => $rows->where('status', 'approved')->where('has_hours', true)->count(),
            'exceptions' => (int) $rows->sum('exceptions'),
        ];

        return Inertia::render('time/timesheets', [
            'period' => $this->periodProps($period),
            'rows' => $rows,
            'totals' => $totals,
            'formats' => collect(PayrollExporter::FORMATS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'exports' => PayrollExport::query()->with('user:id,name')->where('pay_period_id', $period->id)->latest('id')->get()->map(fn (PayrollExport $x) => [
                'id' => $x->id,
                'format' => PayrollExporter::FORMATS[$x->format] ?? $x->format,
                'employees' => $x->employees,
                'hours' => TimesheetCalculator::hours($x->regular_minutes + $x->overtime_minutes),
                'by' => $x->user?->name,
                'at' => $x->created_at?->setTimezone($this->tz())->format('M j, g:i a'),
                'complete' => $x->format !== 'quickbooks' || (bool) ($x->details['complete'] ?? false),
                'note' => $x->note,
            ]),
            'quickbooks' => QuickBooks::integration() !== null,
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $period = $this->period($request);
        $result = TimesheetCalculator::make()->forPeriod($employee, $period);
        $sheet = Timesheet::query()->with('approver:id,name')->where('pay_period_id', $period->id)->where('employee_id', $employee->id)->first();
        $timesheets = Timesheets::make();

        return Inertia::render('time/timesheet', [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
                'yard' => $employee->location?->name,
                'pay_type' => Employee::PAY_TYPES[$employee->pay_type] ?? $employee->pay_type,
                'earns_overtime' => $employee->earnsOvertime(),
                'payroll_id' => $employee->payroll_id,
            ],
            'period' => $this->periodProps($period),
            'timesheet' => [
                'status' => $sheet?->status->value ?? 'open',
                'status_label' => $sheet?->status->label() ?? 'Open',
                'submitted_at' => $sheet?->submitted_at?->setTimezone($this->tz())->format('M j, g:i a'),
                'approved_at' => $sheet?->approved_at?->setTimezone($this->tz())->format('M j, g:i a'),
                'approved_by' => $sheet?->approver?->name,
                'blocker' => $sheet?->isApproved() ? null : $timesheets->blocker($employee, $result),
            ],
            'punch_types' => array_map(fn (PunchType $t) => ['value' => $t->value, 'label' => $t->label()], PunchType::cases()),
            ...$this->presenter()->result($result),
        ]);
    }

    public function approve(Request $request, Employee $employee, Timesheets $timesheets): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $period = PayPeriod::query()->findOrFail($request->integer('period_id'));

        $timesheets->approve($employee, $period, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Approved {$employee->name}’s timesheet."]);

        return back();
    }

    public function unapprove(Request $request, Employee $employee, Timesheets $timesheets): RedirectResponse
    {
        $period = PayPeriod::query()->findOrFail($request->integer('period_id'));
        $timesheets->unapprove($employee, $period);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$employee->name}’s timesheet is open again."]);

        return back();
    }

    public function approveAll(Request $request, PayPeriod $period, Timesheets $timesheets): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $outcome = $timesheets->approveAll($period, $user);

        $message = $outcome['approved'] === 1 ? 'Approved 1 timesheet.' : "Approved {$outcome['approved']} timesheets.";

        if ($outcome['skipped'] !== []) {
            $message .= ' Still to sort out: '.implode('; ', $outcome['skipped']).'.';
        }

        Inertia::flash('toast', ['type' => $outcome['skipped'] === [] ? 'success' : 'warning', 'message' => $message]);

        return back();
    }
}
