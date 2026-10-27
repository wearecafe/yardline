<?php

namespace App\Time;

use App\Enums\ClockState;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\TimePunch;
use App\Support\Files;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Clocking in and out: where someone is in their day, and recording a
 * punch from the kiosk, a phone or the driver app.
 */
class TimeClock
{
    /**
     * A shift left open this long is taken as a missed clock-out: the
     * next clock-in starts a new shift and the old one is flagged.
     */
    public const STALE_HOURS = 16;

    /** A second tap of the same button within this many seconds is the same punch. */
    private const DOUBLE_TAP_SECONDS = 60;

    public function __construct(private TimeSettings $settings) {}

    public static function make(): self
    {
        return new self(TimeSettings::current());
    }

    /**
     * @return array{state: ClockState, last: TimePunch|null, shift_started_at: CarbonImmutable|null, break_started_at: CarbonImmutable|null, missed_out: TimePunch|null}
     */
    public function status(Employee $employee, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $punches = TimePunch::query()
            ->effective()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '<=', $at)
            ->where('punched_at', '>=', $at->subDays(3))
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();

        return $this->statusFrom($punches, $at);
    }

    /**
     * @param  Collection<int, TimePunch>  $punches  in time order
     * @return array{state: ClockState, last: TimePunch|null, shift_started_at: CarbonImmutable|null, break_started_at: CarbonImmutable|null, missed_out: TimePunch|null}
     */
    public function statusFrom(Collection $punches, CarbonImmutable $at): array
    {
        /** @var TimePunch|null $last */
        $last = $punches->last();
        $state = ClockState::after($last?->type);
        $shiftIn = null;
        $breakStart = null;

        foreach ($punches as $punch) {
            if ($punch->type === PunchType::In) {
                $shiftIn = $punch;
            }

            if ($punch->type === PunchType::BreakStart) {
                $breakStart = $punch;
            }
        }

        $missed = null;

        if ($state !== ClockState::Out && $shiftIn !== null && $shiftIn->punched_at->lt($at->subHours(self::STALE_HOURS))) {
            $missed = $shiftIn;
            $state = ClockState::Out;
        }

        return [
            'state' => $state,
            'last' => $last,
            'shift_started_at' => $state === ClockState::Out ? null : $shiftIn?->punched_at,
            'break_started_at' => $state === ClockState::OnBreak ? $breakStart?->punched_at : null,
            'missed_out' => $missed,
        ];
    }

    /**
     * Records a punch. Taps that don't fit (a second clock-in, a break
     * while clocked out) are refused with a message for the screen.
     *
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy_m?: int|null, device_at?: CarbonImmutable|null, photo?: string|null, location_id?: int|null, recorded_by?: int|null}  $context
     */
    public function punch(Employee $employee, PunchType $type, PunchSource $source, array $context = []): TimePunch
    {
        if ($employee->archived_at !== null || ! $employee->tracks_time) {
            TimeRules::fail("{$employee->name} isn’t set up to clock in.");
        }

        $now = CarbonImmutable::now();
        $deviceAt = $context['device_at'] ?? null;

        if ($deviceAt !== null && ($deviceAt->gt($now->addMinutes(5)) || $deviceAt->lt($now->subHours(72)))) {
            TimeRules::fail('That punch is too far from now to record. Ask a manager to add it.');
        }

        $deviceAt = $deviceAt?->utc();
        $at = $deviceAt !== null && $deviceAt->lt($now) ? $deviceAt : $now->utc();

        return DB::transaction(function () use ($employee, $type, $source, $context, $at, $deviceAt) {
            // One punch at a time per person.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $repeat = TimePunch::query()
                ->effective()
                ->where('employee_id', $employee->id)
                ->where('type', $type->value)
                ->when(
                    $deviceAt !== null,
                    fn ($q) => $q->where('device_at', $deviceAt),
                    fn ($q) => $q->where('punched_at', '>=', $at->subSeconds(self::DOUBLE_TAP_SECONDS)),
                )
                ->latest('punched_at')
                ->first();

            if ($repeat !== null) {
                return $repeat;
            }

            $this->ensureOpenPeriod($at);

            $status = $this->status($employee, $at);

            if (! in_array($type, $status['state']->allowed(), true)) {
                TimeRules::fail(match ($status['state']) {
                    ClockState::Out => "{$employee->name} isn’t clocked in.",
                    ClockState::Working => "{$employee->name} is already clocked in.",
                    ClockState::OnBreak => "{$employee->name} is on a break. End it or clock out.",
                });
            }

            $where = $this->where($employee, $type, $source, $context);
            $photo = $context['photo'] ?? null;

            $punch = TimePunch::query()->create([
                'employee_id' => $employee->id,
                'type' => $type,
                'punched_at' => $at,
                'source' => $source,
                'recorded_by' => $context['recorded_by'] ?? null,
                'latitude' => $context['latitude'] ?? null,
                'longitude' => $context['longitude'] ?? null,
                'accuracy_m' => $context['accuracy_m'] ?? null,
                'device_at' => $deviceAt,
                'photo_path' => $photo !== null ? $this->storePhoto($photo) : null,
                ...$where,
            ]);

            Timesheets::reopenFor($employee, CarbonImmutable::parse($at->setTimezone($this->timezone())->toDateString()), submittedToo: false);

            return $punch;
        });
    }

    public function ensureOpenPeriod(CarbonImmutable $at): void
    {
        $tz = $this->timezone();
        $period = PayPeriod::query()
            ->whereDate('starts_on', '<=', $at->setTimezone($tz)->toDateString())
            ->whereDate('ends_on', '>=', $at->setTimezone($tz)->toDateString())
            ->first();

        if ($period !== null && ! $period->isOpen()) {
            TimeRules::fail('That day is in a pay period that’s been locked for payroll.');
        }
    }

    /**
     * Where the punch was made: the kiosk's yard, or the phone's position
     * checked against the yards.
     *
     * @param  array<string, mixed>  $context
     * @return array{location_id: int|null, geofence: string|null}
     */
    private function where(Employee $employee, PunchType $type, PunchSource $source, array $context): array
    {
        if ($source === PunchSource::Kiosk || $source === PunchSource::Manager) {
            $location = $context['location_id'] ?? null;

            return ['location_id' => is_int($location) ? $location : null, 'geofence' => null];
        }

        $lat = isset($context['latitude']) ? (float) $context['latitude'] : null;
        $lng = isset($context['longitude']) ? (float) $context['longitude'] : null;
        $accuracy = isset($context['accuracy_m']) ? (int) $context['accuracy_m'] : null;

        $result = (new Geofence($this->settings))->check($lat, $lng, $accuracy);

        // Take-home trucks start and end the day wherever the truck is.
        if ($employee->take_home_truck && in_array($type, [PunchType::In, PunchType::Out], true) && $result['geofence'] === Geofence::OUTSIDE) {
            return ['location_id' => null, 'geofence' => Geofence::TAKE_HOME];
        }

        return $result;
    }

    private function storePhoto(string $dataUrl): ?string
    {
        try {
            return Files::storeDataUrl($dataUrl, 'time-photos');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function timezone(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }
}
