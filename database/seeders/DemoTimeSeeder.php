<?php

namespace Database\Seeders;

use App\Enums\AdjustmentAction;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Http\Controllers\Time\EmployeeController;
use App\Models\Employee;
use App\Models\Location;
use App\Models\PayPeriod;
use App\Models\Tenant;
use App\Models\TimeAdjustment;
use App\Models\TimePunch;
use App\Models\User;
use App\Time\Geofence;
use App\Time\PayPeriods;
use App\Time\PayrollExporter;
use App\Time\TimeSettings;
use App\Time\Timesheets;
use Carbon\CarbonImmutable;

/**
 * Time and attendance for the demo yard: who clocks in (sign-ins and
 * yard hands on the counter tablet), three weeks of punches, today's
 * crew on the clock, a few things for the manager to look at, and last
 * pay period approved, locked and exported.
 *
 * Run inside the demo tenant, after its users and yards exist.
 */
final class DemoTimeSeeder
{
    /** @var array<string, Employee> */
    private array $people = [];

    private CarbonImmutable $now;

    /**
     * @param  array<string, User>  $users  by email
     */
    public function __construct(
        private Tenant $tenant,
        private array $users,
        private Location $waterdown,
        private Location $stoneyCreek,
        private string $tz,
    ) {
        $this->now = CarbonImmutable::now($tz);
    }

    public function run(): void
    {
        $this->waterdown->update(['latitude' => 43.33352, 'longitude' => -79.87953, 'geofence_m' => 250]);
        $this->stoneyCreek->update(['latitude' => 43.22050, 'longitude' => -79.71200, 'geofence_m' => 250]);

        $settings = $this->tenant->settings ?? [];
        $settings['time'] = [...TimeSettings::forProvince('ON')->toArray(), 'enabled' => true, 'photo' => 'selected', 'adp_company_code' => 'ESC'];
        $this->tenant->update(['settings' => $settings]);

        $this->employees();

        $periods = PayPeriods::make();
        $current = $periods->current($this->tz);
        $previous = $periods->previous($current);

        $this->history($previous->starts_on, $current);
        $this->today();
        $this->thingsToLookAt($current);
        $this->closePeriod($previous);
    }

    private function employees(): void
    {
        EmployeeController::provision();

        $byUser = fn (string $email) => Employee::query()->where('user_id', $this->users[$email]->id)->firstOrFail();

        $setup = [
            'lisa' => [$byUser('lisa.marchetti@example.com'), ['job_title' => 'Office manager', 'pay_type' => 'salaried', 'overtime_exempt' => true, 'location_id' => $this->waterdown->id, 'payroll_id' => '1002'], null],
            'ryan' => [$byUser('ryan.tran@example.com'), ['job_title' => 'Counter sales', 'location_id' => $this->waterdown->id, 'payroll_id' => '1003'], '1111'],
            'owen' => [$byUser('owen.bakker@example.com'), ['job_title' => 'Yard lead', 'location_id' => $this->waterdown->id, 'payroll_id' => '1004'], '2222'],
            'sam' => [$byUser('sam.rinaldi@example.com'), ['job_title' => 'Driver', 'location_id' => $this->waterdown->id, 'payroll_id' => '1005'], null],
        ];

        foreach ($setup as $key => [$employee, $attributes, $pin]) {
            $employee->fill($attributes);
            $employee->setPin($pin);
            $employee->save();
            $this->people[$key] = $employee;
        }

        // Yard hands who only punch at the counter tablet.
        $hands = [
            'dave' => ['Dave Kowalski', 'Yard hand', $this->waterdown, '1006', '3333', false],
            'marco' => ['Marco Silva', 'Yard hand', $this->stoneyCreek, '1007', '4444', false],
            'priya' => ['Priya Nair', 'Loader operator', $this->stoneyCreek, '1008', '5555', true],
        ];

        foreach ($hands as $key => [$name, $job, $yard, $payroll, $pin, $photo]) {
            $employee = new Employee([
                'name' => $name,
                'job_title' => $job,
                'location_id' => $yard->id,
                'payroll_id' => $payroll,
                'photo_on_punch' => $photo,
            ]);
            $employee->setPin($pin);
            $employee->save();
            $this->people[$key] = $employee;
        }
    }

    /**
     * Weekdays from the start of last pay period up to yesterday, plus
     * Saturday mornings for the yard crew.
     */
    private function history(CarbonImmutable $from, PayPeriod $current): void
    {
        // [key, start, end, source]
        $plans = [
            ['owen', '06:45', '16:15', PunchSource::Kiosk],
            ['ryan', '07:00', '15:30', PunchSource::Kiosk],
            ['sam', '06:30', '15:00', PunchSource::Driver],
            ['dave', '07:00', '15:30', PunchSource::Kiosk],
            ['marco', '07:00', '15:30', PunchSource::Kiosk],
            ['priya', '06:45', '15:15', PunchSource::Kiosk],
            ['lisa', '08:30', '16:30', PunchSource::Mobile],
        ];

        $index = 0;

        for ($day = CarbonImmutable::parse($from->toDateString(), $this->tz); $day->lt($this->now->startOfDay()); $day = $day->addDay()) {
            $index++;

            if ($day->isSunday()) {
                continue;
            }

            foreach ($plans as $p => [$key, $start, $end, $source]) {
                // Saturday mornings: the yard crew and the counter only.
                if ($day->isSaturday() && ! in_array($key, ['owen', 'ryan', 'dave'], true)) {
                    continue;
                }

                $jitter = (($index * 7 + $p * 13) % 15) - 7;
                $in = $day->setTimeFromTimeString($start)->addMinutes($jitter);
                $out = $day->isSaturday()
                    ? $day->setTimeFromTimeString('11:30')->addMinutes(abs($jitter))
                    : $day->setTimeFromTimeString($end)->addMinutes((($index * 5 + $p * 3) % 20) - 5);

                $this->punch($key, PunchType::In, $in, $source);

                // Lunch in the middle of the day, so neither half runs past five hours.
                if (! $day->isSaturday()) {
                    $lunchAt = $in->addSeconds(intdiv($out->getTimestamp() - $in->getTimestamp(), 2))->subMinutes(15);
                    $this->punch($key, PunchType::BreakStart, $lunchAt, $source);
                    $this->punch($key, PunchType::BreakEnd, $lunchAt->addMinutes(30), $source);
                }

                $this->punch($key, PunchType::Out, $out, $source);
            }
        }
    }

    /**
     * Today's crew. The demo is rebuilt overnight, so these are seeded
     * ahead of time: each person shows as clocked in once their time
     * comes, and Dave goes on lunch at noon.
     */
    private function today(): void
    {
        if ($this->now->isSunday()) {
            return;
        }

        $today = $this->now->startOfDay();
        $starts = [
            'sam' => ['06:31', PunchSource::Driver],
            'owen' => ['06:52', PunchSource::Kiosk],
            'priya' => ['06:47', PunchSource::Kiosk],
            'ryan' => ['06:58', PunchSource::Kiosk],
            'dave' => ['07:04', PunchSource::Kiosk],
            'marco' => ['07:09', PunchSource::Kiosk],
            'lisa' => ['08:34', PunchSource::Mobile],
        ];

        foreach ($starts as $key => [$time, $source]) {
            $this->punch($key, PunchType::In, $today->setTimeFromTimeString($time), $source);
        }

        $this->punch('dave', PunchType::BreakStart, $today->setTimeFromTimeString('12:02'), PunchSource::Kiosk);
        $this->punch('dave', PunchType::BreakEnd, $today->setTimeFromTimeString('12:33'), PunchSource::Kiosk);
    }

    /**
     * A missed clock-out, a punch away from the yard, a skipped lunch and
     * a disputed correction, all in the current pay period.
     */
    private function thingsToLookAt(PayPeriod $current): void
    {
        $days = TimePunch::query()
            ->where('type', PunchType::Out->value)
            ->where('punched_at', '>=', CarbonImmutable::parse($current->starts_on->toDateString(), $this->tz)->utc())
            ->get()
            ->groupBy(fn (TimePunch $p) => $p->punched_at->setTimezone($this->tz)->toDateString())
            ->sortKeysDesc();

        $recent = $days->keys()->values();

        if ($recent->isEmpty()) {
            return;
        }

        // Dave forgot to clock out on the most recent day.
        $days->get($recent[0])?->firstWhere('employee_id', $this->people['dave']->id)?->delete();

        // Sam clocked out at the last drop of a day, away from the yard.
        $samOut = ($days->get($recent[1] ?? $recent[0]))?->firstWhere('employee_id', $this->people['sam']->id);
        $samOut?->forceFill(['geofence' => Geofence::OUTSIDE, 'location_id' => null, 'latitude' => 43.2557, 'longitude' => -79.8711])->save();

        // Marco worked straight through lunch.
        $marcoDay = $recent[1] ?? $recent[0];
        TimePunch::query()
            ->where('employee_id', $this->people['marco']->id)
            ->whereIn('type', [PunchType::BreakStart->value, PunchType::BreakEnd->value])
            ->get()
            ->filter(fn (TimePunch $p) => $p->punched_at->setTimezone($this->tz)->toDateString() === $marcoDay)
            ->each(fn (TimePunch $p) => $p->delete());

        // Lisa moved Ryan's clock-out; Ryan says he stayed later.
        $ryanOut = ($days->get($recent[2] ?? $recent[0]))?->firstWhere('employee_id', $this->people['ryan']->id);

        if ($ryanOut !== null) {
            $lisa = $this->users['lisa.marchetti@example.com'];
            $newAt = $ryanOut->punched_at->subMinutes(30);
            $adjustment = TimeAdjustment::query()->create([
                'employee_id' => $ryanOut->employee_id,
                'time_punch_id' => $ryanOut->id,
                'action' => AdjustmentAction::Change,
                'punch_type' => PunchType::Out,
                'old_at' => $ryanOut->punched_at,
                'new_at' => $newAt,
                'reason' => 'Counter closed at 3:30; the till was counted by then',
                'user_id' => $lisa->id,
            ]);
            $ryanOut->forceFill(['superseded_at' => now(), 'superseded_by_adjustment_id' => $adjustment->id])->save();
            TimePunch::query()->create([
                'employee_id' => $ryanOut->employee_id,
                'type' => PunchType::Out,
                'punched_at' => $newAt,
                'source' => PunchSource::Manager,
                'recorded_by' => $lisa->id,
                'location_id' => $ryanOut->location_id,
                'adjustment_id' => $adjustment->id,
            ]);
            $adjustment->forceFill(['disputed_at' => now(), 'dispute_note' => 'I stayed to help load the Brantwood order after close'])->save();
        }
    }

    /**
     * Last pay period: everyone approved, locked and sent to payroll.
     */
    private function closePeriod(PayPeriod $previous): void
    {
        $owner = $this->users['gord.hessels@example.com'];
        $timesheets = Timesheets::make();

        foreach ($timesheets->people($previous) as $employee) {
            $timesheets->approve($employee, $previous, $owner);
        }

        $timesheets->lock($previous, $owner);
        PayrollExporter::make()->summary($previous, $owner);
    }

    private function punch(string $key, PunchType $type, CarbonImmutable $at, PunchSource $source): void
    {
        $employee = $this->people[$key];
        $yard = $employee->location_id === $this->stoneyCreek->id ? $this->stoneyCreek : $this->waterdown;
        $phone = $source !== PunchSource::Kiosk;

        TimePunch::query()->create([
            'employee_id' => $employee->id,
            'type' => $type,
            'punched_at' => $at->utc(),
            'source' => $source,
            'recorded_by' => $employee->user_id,
            'location_id' => $yard->id,
            'latitude' => $phone ? $yard->latitude : null,
            'longitude' => $phone ? $yard->longitude : null,
            'accuracy_m' => $phone ? 12 : null,
            'geofence' => $phone ? Geofence::INSIDE : null,
        ]);
    }
}
