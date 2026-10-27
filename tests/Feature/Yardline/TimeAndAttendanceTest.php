<?php

namespace Tests\Feature\YardRunner;

use App\Accounting\QuickBooks\QuickBooks;
use App\Enums\AdjustmentAction;
use App\Enums\IntegrationProvider;
use App\Enums\PayPeriodStatus;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Enums\TimesheetStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Integration;
use App\Models\Location;
use App\Models\PayPeriod;
use App\Models\Tenant;
use App\Models\TimeAdjustment;
use App\Models\TimePunch;
use App\Models\Timesheet;
use App\Models\User;
use App\Notifications\TimeCorrectionMessage;
use App\Tenancy\CurrentTenant;
use App\Time\Geofence;
use App\Time\PayPeriods;
use App\Time\TimeClock;
use App\Time\TimeSettings;
use App\Time\TimesheetCalculator;
use App\Time\TimesheetDay;
use App\Time\TimesheetResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeQuickBooks;
use Tests\TestCase;

/**
 * Time and attendance: clocking in from the counter tablet, phones and
 * the driver app; shifts, breaks and weekly overtime by province;
 * exceptions; manager corrections and disputes; approval, locking and
 * payroll export.
 */
class TimeAndAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Toronto';

    /** The Waterdown yard's gate. */
    private const YARD = [43.33352, -79.87953];

    /** A job site in Hamilton, about 6 km away. */
    private const SITE = [43.2557, -79.8711];

    private User $owner;

    private User $owen;

    private User $sam;

    private Tenant $tenant;

    private Location $yard;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('yardline.files_disk'));

        $this->owner = $this->signInToNewTenant();
        $this->tenant = CurrentTenant::get() ?? Tenant::factory()->create();
        $this->tenant->update(['timezone' => self::TZ]);
        CurrentTenant::set($this->tenant->refresh());

        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['name' => 'Waterdown yard', 'region' => 'ON']);

        $this->owen = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Staff, 'name' => 'Owen Bakker']);
        $this->sam = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Driver, 'name' => 'Sam Rinaldi']);

        // Monday, Oct 19 2026: the second week of the Oct 11 – Oct 24 pay period.
        $this->travelTo($this->at('2026-10-19 06:00'));
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, self::TZ);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return [
            ...TimeSettings::forProvince('ON')->toArray(),
            'enabled' => true,
            'yards' => [['id' => $this->yard->id, 'latitude' => self::YARD[0], 'longitude' => self::YARD[1], 'geofence_m' => 250]],
            ...$overrides,
        ];
    }

    private function enable(array $overrides = []): void
    {
        $this->actingAs($this->owner)->put(route('time-settings.update'), $this->settings($overrides))->assertSessionHasNoErrors();
    }

    private function employee(User $user): Employee
    {
        return Employee::query()->where('user_id', $user->id)->firstOrFail();
    }

    /**
     * Punches from someone's own phone at a local time.
     *
     * @param  array{0: float, 1: float}|null  $where
     */
    private function punch(User $user, PunchType $type, string $local, ?array $where = self::YARD): void
    {
        $this->travelTo($this->at($local));

        $this->actingAs($user)->post(route('time.self.punch'), [
            'type' => $type->value,
            'latitude' => $where[0] ?? null,
            'longitude' => $where[1] ?? null,
            'accuracy' => $where === null ? null : 15,
        ])->assertSessionHasNoErrors();
    }

    /**
     * A day from in to out, with a break, straight through the clock.
     */
    private function day(Employee $employee, string $date, string $in, string $out, ?string $breakStart = null, ?string $breakEnd = null): void
    {
        $this->clock($employee, PunchType::In, "{$date} {$in}");

        if ($breakStart !== null && $breakEnd !== null) {
            $this->clock($employee, PunchType::BreakStart, "{$date} {$breakStart}");
            $this->clock($employee, PunchType::BreakEnd, "{$date} {$breakEnd}");
        }

        $this->clock($employee, PunchType::Out, "{$date} {$out}");
    }

    /**
     * One punch from a phone at the yard, at a local time.
     */
    private function clock(Employee $employee, PunchType $type, string $local): void
    {
        $this->travelTo($this->at($local));
        TimeClock::make()->punch($employee, $type, PunchSource::Mobile, ['latitude' => self::YARD[0], 'longitude' => self::YARD[1], 'accuracy_m' => 10]);
    }

    private function dayOf(TimesheetResult $result, string $date): TimesheetDay
    {
        $day = collect($result->days)->first(fn ($d) => $d->date->toDateString() === $date);
        $this->assertNotNull($day);

        return $day;
    }

    private function period(): PayPeriod
    {
        return PayPeriods::make()->containing($this->at('2026-10-19 12:00'));
    }

    public function test_it_stays_hidden_until_the_business_turns_it_on(): void
    {
        $this->actingAs($this->owner)->get(route('time.board'))->assertRedirect(route('time-settings.edit'));
        $this->actingAs($this->owen)->get(route('time.self.show'))->assertNotFound();

        $this->enable();

        $this->actingAs($this->owner)->get(route('time.board'))->assertOk();
        $this->actingAs($this->owen)->get(route('time.self.show'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('time/me')->where('clock.state', 'out'));
    }

    public function test_turning_it_on_sets_up_everyone_who_signs_in(): void
    {
        $this->enable();

        $this->assertSame(3, Employee::query()->count());
        $this->assertFalse($this->employee($this->owner)->tracks_time);
        $this->assertSame('salaried', $this->employee($this->owner)->pay_type);
        $this->assertTrue($this->employee($this->owen)->tracks_time);
        $this->assertSame('Driver', $this->employee($this->sam)->job_title);

        // The yard's position is kept for checking phone punches.
        $this->assertSame(250, $this->yard->refresh()->geofence_m);

        // Saving again never adds anyone twice.
        $this->enable(['photo' => 'everyone']);
        $this->assertSame(3, Employee::query()->count());
        $this->assertSame('everyone', TimeSettings::current()->photo);
    }

    public function test_the_time_clock_takes_a_pin(): void
    {
        $this->enable();

        $this->actingAs($this->owner)->post(route('time.employees.store'), [
            'name' => 'Dave Kowalski',
            'job_title' => 'Yard hand',
            'pay_type' => 'hourly',
            'tracks_time' => true,
            'pin' => '4321',
        ])->assertSessionHasNoErrors();
        $dave = Employee::query()->where('name', 'Dave Kowalski')->firstOrFail();
        $this->assertNull($dave->user_id);
        $this->assertNotSame('4321', $dave->pin_hash);

        // The counter tablet is signed in as whoever is at the counter.
        $this->actingAs($this->owner)->post(route('time.kiosk.punch'), ['employee_id' => $dave->id, 'pin' => '1111', 'type' => 'in', 'location_id' => $this->yard->id])
            ->assertSessionHasErrors(['pin' => 'That PIN doesn’t match.']);
        $this->assertSame(0, TimePunch::query()->count());

        $this->travelTo($this->at('2026-10-19 07:02'));
        $this->post(route('time.kiosk.punch'), ['employee_id' => $dave->id, 'pin' => '4321', 'type' => 'in', 'location_id' => $this->yard->id])
            ->assertSessionHasNoErrors();

        $punch = TimePunch::query()->sole();
        $this->assertSame(PunchSource::Kiosk, $punch->source);
        $this->assertSame($this->yard->id, $punch->location_id);
        $this->assertSame('2026-10-19 11:02', $punch->punched_at->format('Y-m-d H:i'));

        // A second clock-in doesn't fit.
        $this->travelTo($this->at('2026-10-19 07:10'));
        $this->post(route('time.kiosk.punch'), ['employee_id' => $dave->id, 'pin' => '4321', 'type' => 'in'])
            ->assertSessionHasErrors(['time' => 'Dave Kowalski is already clocked in.']);

        $this->get(route('time.kiosk'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('kiosk/time-clock')
            ->where('people', fn ($people) => collect($people)->firstWhere('name', 'Dave Kowalski')['state'] === 'working'));
    }

    public function test_wrong_pins_lock_the_time_clock_for_a_while(): void
    {
        $this->enable();
        $owen = $this->employee($this->owen);
        $owen->setPin('2468');
        $owen->save();

        foreach (range(1, 5) as $_) {
            $this->actingAs($this->owner)->post(route('time.kiosk.punch'), ['employee_id' => $owen->id, 'pin' => '0000', 'type' => 'in']);
        }

        $this->post(route('time.kiosk.punch'), ['employee_id' => $owen->id, 'pin' => '2468', 'type' => 'in'])
            ->assertSessionHasErrors('pin');
        $this->assertSame(0, TimePunch::query()->count());
    }

    public function test_the_time_clock_photographs_the_people_it_should(): void
    {
        $this->enable(['photo' => 'selected']);
        $owen = $this->employee($this->owen);
        $owen->setPin('2468');
        $owen->photo_on_punch = true;
        $owen->save();

        $this->assertTrue($owen->needsPhoto(TimeSettings::current()));
        $this->assertFalse($this->employee($this->sam)->needsPhoto(TimeSettings::current()));

        $photo = 'data:image/jpeg;base64,'.base64_encode('not really a jpeg');
        $this->actingAs($this->owner)->post(route('time.kiosk.punch'), ['employee_id' => $owen->id, 'pin' => '2468', 'type' => 'in', 'photo' => $photo])
            ->assertSessionHasNoErrors();

        $path = TimePunch::query()->sole()->photo_path;
        $this->assertNotNull($path);
        Storage::disk(config('yardline.files_disk'))->assertExists($path);
    }

    public function test_phone_punches_are_checked_against_the_yard(): void
    {
        $this->enable();
        $this->employee($this->sam)->update(['take_home_truck' => true]);

        $this->punch($this->owen, PunchType::In, '2026-10-19 07:00', self::YARD);
        $this->punch($this->owen, PunchType::Out, '2026-10-19 15:00', self::SITE);
        $this->punch($this->sam, PunchType::In, '2026-10-19 06:30', self::SITE);
        $this->punch($this->sam, PunchType::BreakStart, '2026-10-19 11:00', self::SITE);
        $this->punch($this->sam, PunchType::BreakEnd, '2026-10-19 11:30', null);

        $owen = TimePunch::query()->where('employee_id', $this->employee($this->owen)->id)->orderBy('punched_at')->get();
        $this->assertSame([Geofence::INSIDE, Geofence::OUTSIDE], $owen->pluck('geofence')->all());
        $this->assertSame($this->yard->id, $owen->first()?->location_id);
        $this->assertSame(PunchSource::Mobile, $owen->first()?->source);

        // A take-home truck starts the day wherever the truck is; a break away is flagged as usual.
        $sam = TimePunch::query()->where('employee_id', $this->employee($this->sam)->id)->orderBy('punched_at')->get();
        $this->assertSame([Geofence::TAKE_HOME, Geofence::OUTSIDE, Geofence::UNKNOWN], $sam->pluck('geofence')->all());
        $this->assertSame(PunchSource::Driver, $sam->first()?->source);

        // Eight hours straight is also past the meal break.
        $result = TimesheetCalculator::make()->forPeriod($this->employee($this->owen), $this->period());
        $this->assertSame(['no_meal', 'outside'], array_column($result->exceptions(), 'kind'));
    }

    public function test_punches_have_to_follow_on_from_each_other(): void
    {
        $this->enable();

        $this->travelTo($this->at('2026-10-19 07:00'));
        $this->actingAs($this->owen)->post(route('time.self.punch'), ['type' => 'break_start'])
            ->assertSessionHasErrors(['time' => 'Owen Bakker isn’t clocked in.']);

        $this->punch($this->owen, PunchType::In, '2026-10-19 07:00');

        // A second tap of the same button is the same punch.
        $this->actingAs($this->owen)->post(route('time.self.punch'), ['type' => 'in'])->assertSessionHasNoErrors();
        $this->assertSame(1, TimePunch::query()->count());

        $this->punch($this->owen, PunchType::BreakStart, '2026-10-19 11:00');
        $this->travelTo($this->at('2026-10-19 11:10'));
        $this->post(route('time.self.punch'), ['type' => 'break_start'])
            ->assertSessionHasErrors(['time' => 'Owen Bakker is on a break. End it or clock out.']);
    }

    public function test_offline_punches_keep_the_time_they_were_made(): void
    {
        $this->enable();
        $this->travelTo($this->at('2026-10-19 07:40'));

        $this->actingAs($this->sam)->post(route('time.self.punch'), [
            'type' => 'in',
            'device_at' => $this->at('2026-10-19 07:00')->toIso8601String(),
        ])->assertSessionHasNoErrors();

        // The same queued punch sent twice is recorded once.
        $this->post(route('time.self.punch'), ['type' => 'in', 'device_at' => $this->at('2026-10-19 07:00')->toIso8601String()])->assertSessionHasNoErrors();

        $punch = TimePunch::query()->sole();
        $this->assertSame('2026-10-19 11:00', $punch->punched_at->format('Y-m-d H:i'));
        $this->assertTrue($punch->sentLate());

        $result = TimesheetCalculator::make()->forPeriod($this->employee($this->sam), $this->period());
        $this->assertContains('sent_late', array_column($result->exceptions(), 'kind'));

        // Nothing from days ago.
        $this->post(route('time.self.punch'), ['type' => 'out', 'device_at' => $this->at('2026-10-14 17:00')->toIso8601String()])
            ->assertSessionHasErrors('time');
    }

    public function test_breaks_are_unpaid_and_overtime_counts_over_the_workweek(): void
    {
        $this->enable();
        $owen = $this->employee($this->owen);

        // Five 10-hour days with a 30-minute lunch: 50 hours.
        foreach (['19', '20', '21', '22', '23'] as $day) {
            $this->day($owen, "2026-10-{$day}", '07:00', '17:30', '12:00', '12:30');
        }

        $result = TimesheetCalculator::make()->forPeriod($owen, $this->period());
        $this->assertSame(44 * 60, $result->regularMinutes);
        $this->assertSame(6 * 60, $result->overtimeMinutes);
        $this->assertSame(5 * 30, $result->breakMinutes);

        // Overtime lands on the days past the threshold: Friday has it all.
        $this->assertSame(6 * 60, $this->dayOf($result, '2026-10-23')->overtimeMinutes);
        $this->assertSame(4 * 60, $this->dayOf($result, '2026-10-23')->regularMinutes);
        $this->assertSame([], $result->exceptions());

        // Quebec's threshold is 40 hours.
        $this->enable(['province' => 'QC', 'weekly_overtime_hours' => 40]);
        $quebec = TimesheetCalculator::make()->forPeriod($owen, $this->period());
        $this->assertSame(10 * 60, $quebec->overtimeMinutes);

        // Salaried and exempt people don't earn overtime.
        $owen->update(['overtime_exempt' => true]);
        $this->assertSame(0, TimesheetCalculator::make()->forPeriod($owen->refresh(), $this->period())->overtimeMinutes);
    }

    public function test_a_week_that_started_in_the_last_period_counts_toward_overtime(): void
    {
        $this->enable(['frequency' => 'semimonthly']);
        $owen = $this->employee($this->owen);

        // Oct 11 – 17 is a Sunday-to-Saturday week; the second half of the month starts on the 16th.
        foreach (['12', '13', '14', '15', '16'] as $day) {
            $this->day($owen, "2026-10-{$day}", '07:00', '16:30', '12:00', '12:30');
        }

        $period = PayPeriods::make()->containing($this->at('2026-10-16 12:00'));
        $this->assertSame(['2026-10-16', '2026-10-31'], [$period->starts_on->toDateString(), $period->ends_on->toDateString()]);

        $result = TimesheetCalculator::make()->forPeriod($owen, $period);
        // 36 hours before the 16th, 9 on the 16th: 1 hour of overtime in this period.
        $this->assertSame(8 * 60, $result->regularMinutes);
        $this->assertSame(60, $result->overtimeMinutes);
    }

    public function test_punches_round_evenly_when_the_business_rounds(): void
    {
        $this->enable(['rounding_minutes' => 15]);
        $owen = $this->employee($this->owen);
        $this->day($owen, '2026-10-19', '06:53', '15:22');

        // 7:00 to 15:15.
        $this->assertSame(8 * 60 + 15, TimesheetCalculator::make()->forPeriod($owen, $this->period())->regularMinutes);
    }

    public function test_missed_clock_outs_long_shifts_and_missing_meals_are_flagged(): void
    {
        $this->enable();
        $owen = $this->employee($this->owen);

        // Monday: 13 hours with no meal break.
        $this->day($owen, '2026-10-19', '05:00', '18:00');

        // Tuesday: in, then never out. Wednesday's clock-in starts a new shift.
        $this->clock($owen, PunchType::In, '2026-10-20 06:30');
        $this->travelTo($this->at('2026-10-21 07:00'));
        $this->assertSame('out', TimeClock::make()->status($owen)['state']->value);
        $this->clock($owen, PunchType::In, '2026-10-21 07:00');

        // Thursday: back after only 9 hours off.
        $this->clock($owen, PunchType::Out, '2026-10-21 15:00');
        $this->clock($owen, PunchType::In, '2026-10-22 00:00');
        $this->travelTo($this->at('2026-10-22 01:00'));

        $result = TimesheetCalculator::make()->forPeriod($owen, $this->period());

        $this->assertSame(
            [['2026-10-19', 'long_shift'], ['2026-10-19', 'no_meal'], ['2026-10-20', 'missed_out'], ['2026-10-21', 'no_meal'], ['2026-10-22', 'short_rest']],
            array_map(fn ($e) => [$e['date'], $e['kind']], $result->exceptions()),
        );

        // Monday counts; the open Tuesday counts nothing until fixed; Thursday is running.
        $this->assertTrue($result->open);
        $this->assertSame(13 * 60, $this->dayOf($result, '2026-10-19')->regularMinutes);
        $this->assertSame(0, $this->dayOf($result, '2026-10-20')->regularMinutes);
        $this->assertSame(60, $this->dayOf($result, '2026-10-22')->regularMinutes);
    }

    public function test_corrections_keep_the_original_punch_and_tell_the_employee(): void
    {
        Notification::fake();
        $this->enable();
        $owen = $this->employee($this->owen);

        // Clocked in at 7:00 and forgot to clock out.
        $this->punch($this->owen, PunchType::In, '2026-10-19 07:00');
        $this->travelTo($this->at('2026-10-20 09:00'));

        $this->actingAs($this->owner)->post(route('time.punches.store', $owen), ['type' => 'out', 'at' => '2026-10-19T15:30', 'reason' => 'Forgot to clock out; left at 3:30'])
            ->assertSessionHasNoErrors();

        $result = TimesheetCalculator::make()->forPeriod($owen, $this->period());
        $this->assertSame(8 * 60 + 30, $result->regularMinutes);
        Notification::assertSentTo($this->owen, TimeCorrectionMessage::class);

        // Moving the clock-in keeps the original, marked superseded.
        $in = TimePunch::query()->effective()->where('type', 'in')->sole();
        $this->put(route('time.punches.update', $in), ['at' => '2026-10-19T07:30', 'reason' => 'Started at 7:30 per the gate log'])->assertSessionHasNoErrors();

        $this->assertNotNull($in->refresh()->superseded_at);
        $this->assertSame('2026-10-19 11:00', $in->punched_at->format('Y-m-d H:i'));
        $moved = TimePunch::query()->effective()->where('type', 'in')->sole();
        $this->assertSame(PunchSource::Manager, $moved->source);
        $this->assertSame(8 * 60, TimesheetCalculator::make()->forPeriod($owen, $this->period())->regularMinutes);

        $change = TimeAdjustment::query()->where('action', AdjustmentAction::Change->value)->sole();
        $this->assertSame($this->owner->id, $change->user_id);
        $this->assertSame('Started at 7:30 per the gate log', $change->reason);

        // Owen disagrees; it can't be approved until that's sorted out.
        $this->actingAs($this->owen)->post(route('time.self.dispute', $change), ['note' => 'I was in at 7:00, I opened the gate'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('time.timesheets.approve', $owen), ['period_id' => $this->period()->id])
            ->assertSessionHasErrors('time');

        $this->post(route('time.adjustments.resolve', $change))->assertSessionHasNoErrors();
        $this->post(route('time.timesheets.approve', $owen), ['period_id' => $this->period()->id])->assertSessionHasNoErrors();

        $sheet = Timesheet::query()->sole();
        $this->assertSame(TimesheetStatus::Approved, $sheet->status);
        $this->assertSame(8 * 60, $sheet->regular_minutes);

        // People can't dispute someone else's changes.
        $this->actingAs($this->sam)->post(route('time.self.dispute', $change), ['note' => 'Hm'])->assertNotFound();

        // The history shows in Owen's own timesheet.
        $this->actingAs($this->owen)->get(route('time.self.show', ['date' => '2026-10-19']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('days.8.date', '2026-10-19')
                ->where('days.8.adjustments.1.description', 'Moved clock in from Mon Oct 19, 7:00 am to Mon Oct 19, 7:30 am')
                ->where('days.8.adjustments.1.open_dispute', false));
    }

    public function test_changes_after_approval_send_the_timesheet_back(): void
    {
        $this->enable();
        $owen = $this->employee($this->owen);
        $this->day($owen, '2026-10-19', '07:00', '15:00');

        $this->actingAs($this->owner)->post(route('time.timesheets.approve', $owen), ['period_id' => $this->period()->id])->assertSessionHasNoErrors();
        $this->assertTrue(Timesheet::query()->sole()->isApproved());

        // Another day worked reopens it.
        $this->day($owen, '2026-10-20', '07:00', '15:00');
        $sheet = Timesheet::query()->sole();
        $this->assertSame(TimesheetStatus::Open, $sheet->status);
        $this->assertNull($sheet->regular_minutes);

        // Owen sends it in; a manager's change sends it back too.
        $this->actingAs($this->owen)->post(route('time.self.submit'), ['period_id' => $this->period()->id])->assertSessionHasNoErrors();
        $this->assertSame(TimesheetStatus::Submitted, $sheet->refresh()->status);

        $this->actingAs($this->owner)->post(route('time.hours.store', $owen), ['date' => '2026-10-20', 'hours' => 1.5, 'reason' => 'Safety training after hours'])->assertSessionHasNoErrors();
        $this->assertSame(TimesheetStatus::Open, $sheet->refresh()->status);
        $this->assertSame(17 * 60 + 30, TimesheetCalculator::make()->forPeriod($owen, $this->period())->regularMinutes);
    }

    public function test_pay_periods_lock_once_ended_and_approved_then_stay_put(): void
    {
        $this->enable();
        $owen = $this->employee($this->owen);
        $this->day($owen, '2026-10-19', '07:00', '15:00');
        $period = $this->period();

        $this->actingAs($this->owner)->post(route('time.periods.lock', $period))->assertSessionHasErrors('time');

        $this->travelTo($this->at('2026-10-26 09:00'));
        $this->post(route('time.periods.lock', $period))->assertSessionHasErrors(['time' => 'Approve these timesheets first: Owen Bakker.']);

        $this->post(route('time.periods.approve-all', $period))->assertSessionHasNoErrors();
        $this->post(route('time.periods.lock', $period))->assertSessionHasNoErrors();
        $this->assertSame(PayPeriodStatus::Locked, $period->refresh()->status);

        // Nothing in a locked period changes; a late fix goes into the current one.
        $punch = TimePunch::query()->where('type', 'out')->sole();
        $this->put(route('time.punches.update', $punch), ['at' => '2026-10-19T16:00', 'reason' => 'Stayed late'])->assertSessionHasErrors('time');
        $this->post(route('time.hours.store', $owen), ['date' => '2026-10-26', 'hours' => 1, 'reason' => 'Stayed late on Oct 19'])->assertSessionHasNoErrors();
        $this->assertSame(60, TimesheetCalculator::make()->forPeriod($owen, PayPeriods::make()->containing($this->at('2026-10-26 12:00')))->regularMinutes);

        // Reopened and locked again; once exported, it never reopens.
        $this->post(route('time.periods.unlock', $period))->assertSessionHasNoErrors();
        $this->post(route('time.periods.lock', $period))->assertSessionHasNoErrors();
        $this->get(route('time.periods.download', ['period' => $period, 'format' => 'summary']))->assertOk();
        $this->assertSame(PayPeriodStatus::Exported, $period->refresh()->status);
        $this->post(route('time.periods.unlock', $period))->assertSessionHasErrors('time');
    }

    public function test_hours_go_to_payroll_as_a_summary_an_adp_import_or_quickbooks_time(): void
    {
        $this->enable();
        $owen = $this->employee($this->owen);
        $sam = $this->employee($this->sam);

        foreach (['19', '20', '21', '22', '23'] as $day) {
            $this->day($owen, "2026-10-{$day}", '07:00', '17:30', '12:00', '12:30');
        }

        $this->day($sam, '2026-10-21', '06:00', '14:00');

        $period = $this->period();
        $this->travelTo($this->at('2026-10-26 09:00'));
        $this->actingAs($this->owner);

        // Not before it's locked.
        $this->get(route('time.periods.download', ['period' => $period, 'format' => 'summary']))->assertSessionHasErrors('format');

        $this->post(route('time.periods.approve-all', $period))->assertSessionHasNoErrors();
        $this->post(route('time.periods.lock', $period))->assertSessionHasNoErrors();

        $csv = (string) $this->get(route('time.periods.download', ['period' => $period, 'format' => 'summary']))->assertOk()->getContent();
        $this->assertStringContainsString('2026-10-11,2026-10-24,"Owen Bakker",,,Hourly,44.00,6.00,50.00', $csv);
        $this->assertStringContainsString('2026-10-11,2026-10-24,"Sam Rinaldi",,,Hourly,8.00,0.00,8.00', $csv);

        // ADP needs the company code and everyone's file number.
        $this->get(route('time.periods.download', ['period' => $period, 'format' => 'adp']))->assertSessionHasErrors('format');
        $this->enable(['adp_company_code' => 'k7x']);
        $this->get(route('time.periods.download', ['period' => $period, 'format' => 'adp']))->assertSessionHasErrors('format');
        $owen->update(['payroll_id' => '001234']);
        $sam->update(['payroll_id' => '001250']);

        $adp = (string) $this->get(route('time.periods.download', ['period' => $period, 'format' => 'adp']))->assertOk()->getContent();
        $this->assertSame("\"Co Code\",\"Batch ID\",\"File #\",\"Reg Hours\",\"O/T Hours\"\nK7X,YL1024,001234,44.00,6.00\nK7X,YL1024,001250,8.00,0.00\n", $adp);

        // QuickBooks: one time activity per person per day, regular and overtime apart.
        $qbo = (new FakeQuickBooks)->install();
        Integration::query()->create([
            'provider' => IntegrationProvider::QuickBooks,
            'external_account' => '9130',
            'credentials' => ['access_token' => 'access', 'refresh_token' => 'refresh', 'access_expires_at' => now()->addHour()->toIso8601String()],
            'settings' => [],
        ]);
        QuickBooks::forget();

        $this->post(route('time.periods.quickbooks', $period))->assertSessionHasNoErrors();

        $activities = $qbo->created('TimeActivity');
        $this->assertCount(7, $activities);
        $this->assertSame(['Owen Bakker', 'Sam Rinaldi'], array_column($qbo->created('Employee'), 'DisplayName'));
        $friday = array_values(array_filter($activities, fn ($a) => $a['TxnDate'] === '2026-10-23'));
        $this->assertSame([[4, 0], [6, 0]], array_map(fn ($a) => [$a['Hours'], $a['Minutes']], $friday));
        $this->assertStringStartsWith('Overtime', $friday[1]['Description']);

        // Sent once.
        $this->post(route('time.periods.quickbooks', $period))->assertSessionHasErrors('format');
        $this->assertCount(7, $qbo->created('TimeActivity'));
    }

    public function test_the_manager_screens_show_who_is_in_and_the_period(): void
    {
        $this->enable();
        $this->punch($this->owen, PunchType::In, '2026-10-19 07:00');
        $this->punch($this->sam, PunchType::In, '2026-10-19 06:30', self::SITE);
        $this->travelTo($this->at('2026-10-19 09:00'));

        $this->actingAs($this->owner)->get(route('time.board'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('time/board')
            ->where('counts.working', 2)
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('name', 'Sam Rinaldi')['where'] === 'Away from the yard'
                && collect($rows)->firstWhere('name', 'Owen Bakker')['today_label'] === '2h'));

        $this->get(route('time.timesheets.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('time/timesheets')
            ->where('period.label', 'Oct 11 – Oct 24, 2026')
            ->where('rows.0.name', 'Sam Rinaldi')
            ->where('rows.0.exception_labels', ['Away from the yard']));

        $this->get(route('time.timesheets.show', $this->employee($this->owen)))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('time/timesheet')->where('timesheet.blocker', 'still clocked in'));

        $this->get(route('time.employees.index'))->assertOk();
        $this->get(route('time-settings.edit'))->assertOk();
    }

    public function test_drivers_get_their_own_time_and_nothing_else(): void
    {
        $this->enable();

        $this->actingAs($this->sam)->get(route('time.self.show'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('driver/time')->where('employee.name', 'Sam Rinaldi'));
        $this->get(route('time.board'))->assertRedirect(route('driver.today'));
        $this->get(route('time.kiosk'))->assertRedirect(route('driver.today'));

        $this->actingAs($this->owen)->get(route('time.board'))->assertForbidden();
        $this->post(route('time.punches.store', $this->employee($this->sam)), ['type' => 'in', 'at' => '2026-10-19T06:00', 'reason' => 'x'])->assertForbidden();
    }

    public function test_one_business_never_sees_another_s_people(): void
    {
        $this->enable();
        $owen = $this->employee($this->owen);

        $this->signInToNewTenant();
        $this->put(route('time-settings.update'), $this->settings(['yards' => []]))->assertSessionHasNoErrors();

        $this->get(route('time.timesheets.show', $owen->id))->assertNotFound();
        $this->post(route('time.kiosk.punch'), ['employee_id' => $owen->id, 'pin' => '1234', 'type' => 'in'])->assertSessionHasErrors('employee_id');
    }

    public function test_pay_periods_follow_the_frequency(): void
    {
        $this->enable();
        $bounds = fn (string $frequency, string $date) => array_map(
            fn (CarbonImmutable $d) => $d->toDateString(),
            (new PayPeriods(new TimeSettings(frequency: $frequency, anchor: '2026-01-04')))->bounds(CarbonImmutable::parse($date)),
        );

        $this->assertSame(['2026-10-11', '2026-10-24'], $bounds('biweekly', '2026-10-19'));
        $this->assertSame(['2025-12-21', '2026-01-03'], $bounds('biweekly', '2026-01-02'));
        $this->assertSame(['2026-10-18', '2026-10-24'], $bounds('weekly', '2026-10-19'));
        $this->assertSame(['2026-02-16', '2026-02-28'], $bounds('semimonthly', '2026-02-20'));
        $this->assertSame(['2026-02-01', '2026-02-15'], $bounds('semimonthly', '2026-02-15'));
    }
}
