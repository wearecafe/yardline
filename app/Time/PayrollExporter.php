<?php

namespace App\Time;

use App\Accounting\QuickBooks\QuickBooks;
use App\Accounting\QuickBooks\QuickBooksClient;
use App\Accounting\QuickBooks\QuickBooksException;
use App\Enums\PayPeriodStatus;
use App\Models\Employee;
use App\Models\Integration;
use App\Models\PayPeriod;
use App\Models\PayrollExport;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sends a locked pay period's approved hours to payroll, three ways:
 *
 *  - summary: hours per person for the period, for any payroll service
 *    or the accountant
 *  - adp: a pay data import (company code, batch, file number, regular
 *    and overtime hours)
 *  - quickbooks: time activities in QuickBooks Online, one per person
 *    per day, which QuickBooks Payroll picks up
 *
 * Hours are the totals frozen when each timesheet was approved.
 */
class PayrollExporter
{
    public const FORMATS = [
        'summary' => 'Hours summary (CSV)',
        'adp' => 'ADP pay data import (CSV)',
        'quickbooks' => 'QuickBooks Online time activities',
    ];

    public function __construct(private Timesheets $timesheets, private TimeSettings $settings) {}

    public static function make(): self
    {
        return new self(Timesheets::make(), TimeSettings::current());
    }

    /**
     * @return array{filename: string, csv: string}
     */
    public function summary(PayPeriod $period, User $by): array
    {
        $sheets = $this->ready($period);
        $rows = [['Period start', 'Period end', 'Employee', 'Payroll ID', 'Home yard', 'Pay type', 'Regular hours', 'Overtime hours', 'Total hours']];

        foreach ($sheets as $sheet) {
            $e = $sheet->employee;
            $rows[] = [
                $period->starts_on->toDateString(),
                $period->ends_on->toDateString(),
                $e->name,
                (string) $e->payroll_id,
                $e->location->name ?? '',
                Employee::PAY_TYPES[$e->pay_type] ?? $e->pay_type,
                self::decimalHours((int) $sheet->regular_minutes),
                self::decimalHours((int) $sheet->overtime_minutes),
                self::decimalHours((int) $sheet->regular_minutes + (int) $sheet->overtime_minutes),
            ];
        }

        $this->record($period, 'summary', $sheets, $by);

        return ['filename' => 'hours-'.$period->starts_on->toDateString().'-to-'.$period->ends_on->toDateString().'.csv', 'csv' => self::csv($rows)];
    }

    /**
     * @return array{filename: string, csv: string}
     */
    public function adp(PayPeriod $period, User $by): array
    {
        $sheets = $this->ready($period);

        if ($this->settings->adpCompanyCode === '') {
            TimeRules::fail('Add your ADP company code under Settings → Time first.', 'format');
        }

        $missing = $sheets->filter(fn (Timesheet $s) => blank($s->employee->payroll_id))->map(fn (Timesheet $s) => $s->employee->name);

        if ($missing->isNotEmpty()) {
            TimeRules::fail('Add an ADP file number (payroll ID) for '.$missing->implode(', ').' under Team → Employees.', 'format');
        }

        $rows = [['Co Code', 'Batch ID', 'File #', 'Reg Hours', 'O/T Hours']];

        foreach ($sheets as $sheet) {
            $rows[] = [
                $this->settings->adpCompanyCode,
                $this->settings->adpBatchId.$period->ends_on->format('md'),
                (string) $sheet->employee->payroll_id,
                self::decimalHours((int) $sheet->regular_minutes),
                self::decimalHours((int) $sheet->overtime_minutes),
            ];
        }

        $this->record($period, 'adp', $sheets, $by);

        return ['filename' => 'EPI'.$this->settings->adpCompanyCode.$period->ends_on->format('md').'.csv', 'csv' => self::csv($rows)];
    }

    /**
     * Pushes each person's approved days to QuickBooks as time
     * activities. Safe to run again after a failure: people already sent
     * are skipped.
     */
    public function quickbooks(PayPeriod $period, User $by, ?Integration $integration = null): PayrollExport
    {
        $integration ??= QuickBooks::integration();

        if ($integration === null) {
            TimeRules::fail('Connect QuickBooks Online under Settings → QuickBooks first.', 'format');
        }

        $sheets = $this->ready($period);
        $export = PayrollExport::query()->where('pay_period_id', $period->id)->where('format', 'quickbooks')->latest('id')->first();

        if ($export !== null && ($export->details['complete'] ?? false)) {
            TimeRules::fail('These hours are already in QuickBooks.', 'format');
        }

        $export ??= PayrollExport::query()->create([
            'pay_period_id' => $period->id,
            'format' => 'quickbooks',
            'employees' => 0,
            'regular_minutes' => 0,
            'overtime_minutes' => 0,
            'user_id' => $by->id,
            'details' => ['sent' => [], 'complete' => false],
        ]);

        /** @var list<int> $sent */
        $sent = array_values(array_map('intval', (array) ($export->details['sent'] ?? [])));
        $qbo = QuickBooksClient::for($integration);
        $calculator = TimesheetCalculator::make();

        foreach ($sheets as $sheet) {
            $employee = $sheet->employee;

            if (in_array($employee->id, $sent, true)) {
                continue;
            }

            try {
                $ref = $this->qboEmployee($qbo, $integration, $employee);
                $result = $calculator->forPeriod($employee, $period);

                foreach ($result->days as $day) {
                    foreach (['Regular time' => $day->regularMinutes, 'Overtime' => $day->overtimeMinutes] as $what => $minutes) {
                        if ($minutes <= 0) {
                            continue;
                        }

                        $qbo->post('timeactivity', [
                            'TxnDate' => $day->date->toDateString(),
                            'NameOf' => 'Employee',
                            'EmployeeRef' => ['value' => $ref],
                            'Hours' => intdiv($minutes, 60),
                            'Minutes' => $minutes % 60,
                            'Description' => "{$what} · YardRunner pay period ".$period->label(),
                        ]);
                    }
                }
            } catch (QuickBooksException $e) {
                $export->forceFill(['details' => ['sent' => $sent, 'complete' => false], 'note' => "Stopped at {$employee->name}: {$e->getMessage()}"])->save();

                TimeRules::fail("QuickBooks stopped at {$employee->name}: {$e->getMessage()} The people before them were sent; try again to send the rest.", 'format');
            }

            $sent[] = $employee->id;
            $export->forceFill([
                'details' => ['sent' => $sent, 'complete' => false],
                'employees' => count($sent),
                'regular_minutes' => $export->regular_minutes + (int) $sheet->regular_minutes,
                'overtime_minutes' => $export->overtime_minutes + (int) $sheet->overtime_minutes,
            ])->save();
        }

        $export->forceFill(['details' => ['sent' => $sent, 'complete' => true], 'note' => null])->save();
        $this->timesheets->markExported($period);

        return $export;
    }

    /**
     * @return Collection<int, Timesheet>
     */
    private function ready(PayPeriod $period): Collection
    {
        if ($period->status === PayPeriodStatus::Open) {
            TimeRules::fail('Lock the pay period before exporting it.', 'format');
        }

        return $this->timesheets->approved($period)->load('employee.location');
    }

    /**
     * @param  Collection<int, Timesheet>  $sheets
     */
    private function record(PayPeriod $period, string $format, Collection $sheets, User $by): void
    {
        PayrollExport::query()->create([
            'pay_period_id' => $period->id,
            'format' => $format,
            'employees' => $sheets->count(),
            'regular_minutes' => (int) $sheets->sum('regular_minutes'),
            'overtime_minutes' => (int) $sheets->sum('overtime_minutes'),
            'user_id' => $by->id,
        ]);

        $this->timesheets->markExported($period);
    }

    /**
     * The QuickBooks employee for someone: the one with their name, or a
     * new one. Remembered on the integration.
     */
    private function qboEmployee(QuickBooksClient $qbo, Integration $integration, Employee $employee): string
    {
        $settings = $integration->settings ?? [];
        $refs = is_array($settings['refs'] ?? null) ? $settings['refs'] : [];
        $key = "employee:{$employee->id}";

        if (isset($refs[$key]) && is_string($refs[$key])) {
            return $refs[$key];
        }

        $name = trim((string) preg_replace('/[:\t\r\n]+/', ' ', $employee->name));
        $found = $qbo->query("select Id from Employee where DisplayName = '".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."'")[0]['Id'] ?? null;

        if ($found === null) {
            $parts = preg_split('/\s+/', $name) ?: [$name];
            $family = count($parts) > 1 ? (string) array_pop($parts) : '';
            $found = $qbo->post('employee', array_filter([
                'DisplayName' => $name,
                'GivenName' => implode(' ', $parts),
                'FamilyName' => $family,
            ]))['Employee']['Id'] ?? null;
        }

        $id = (string) $found;
        $refs[$key] = $id;
        $integration->forceFill(['settings' => [...$settings, 'refs' => $refs]])->save();

        return $id;
    }

    /** 465 minutes → "7.75". */
    public static function decimalHours(int $minutes): string
    {
        return number_format($minutes / 60, 2, '.', '');
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            return '';
        }

        foreach ($rows as $row) {
            // Neutralise spreadsheet formulas in names.
            fputcsv($out, array_map(fn (string $v) => preg_match('/^[=+\-@]/', $v) === 1 && ! is_numeric($v) ? "'".$v : $v, $row), escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
