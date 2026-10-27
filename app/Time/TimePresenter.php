<?php

namespace App\Time;

use App\Enums\AdjustmentAction;
use App\Models\TimeAdjustment;
use App\Models\TimePunch;
use App\Support\Files;
use Carbon\CarbonImmutable;

/**
 * Timesheets and punches as the screens show them, in the yard's time
 * zone.
 */
final class TimePresenter
{
    public function __construct(private string $timezone) {}

    /**
     * @return array<string, mixed>
     */
    public function result(TimesheetResult $result): array
    {
        return [
            'days' => array_map(fn (TimesheetDay $d) => $this->day($d), $result->days),
            'totals' => $this->totals($result),
            'exceptions' => $result->exceptions(),
        ];
    }

    /**
     * @return array{regular: int, overtime: int, breaks: int, total: int, regular_label: string, overtime_label: string, total_label: string, open: bool}
     */
    public function totals(TimesheetResult $result): array
    {
        return [
            'regular' => $result->regularMinutes,
            'overtime' => $result->overtimeMinutes,
            'breaks' => $result->breakMinutes,
            'total' => $result->totalMinutes(),
            'regular_label' => TimesheetCalculator::hours($result->regularMinutes),
            'overtime_label' => TimesheetCalculator::hours($result->overtimeMinutes),
            'total_label' => TimesheetCalculator::hours($result->totalMinutes()),
            'open' => $result->open,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function day(TimesheetDay $day): array
    {
        return [
            'date' => $day->date->toDateString(),
            'label' => $day->date->format('D M j'),
            'weekday' => $day->date->format('D'),
            'is_today' => $day->date->toDateString() === CarbonImmutable::now($this->timezone)->toDateString(),
            'shifts' => array_map(fn (Shift $s) => [
                'punches' => array_map(fn (TimePunch $p) => $this->punch($p), $s->punches),
                'worked' => $s->workedMinutes,
                'worked_label' => TimesheetCalculator::hours($s->workedMinutes),
                'breaks' => $s->breakMinutes,
                'open' => $s->open,
                'incomplete' => $s->incomplete,
                'auto_meal' => $s->autoMeal,
            ], $day->shifts),
            'strays' => array_map(fn (TimePunch $p) => $this->punch($p), $day->strays),
            'adjustments' => array_map(fn (TimeAdjustment $a) => $this->adjustment($a), $day->adjustments),
            'worked' => $day->regularMinutes + $day->overtimeMinutes,
            'worked_label' => TimesheetCalculator::hours($day->regularMinutes + $day->overtimeMinutes),
            'regular' => $day->regularMinutes,
            'overtime' => $day->overtimeMinutes,
            'overtime_label' => TimesheetCalculator::hours($day->overtimeMinutes),
            'breaks' => $day->breakMinutes,
            'exceptions' => $day->exceptions,
            'empty' => $day->isEmpty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function punch(TimePunch $punch): array
    {
        $local = $punch->punched_at->setTimezone($this->timezone);

        return [
            'id' => $punch->id,
            'type' => $punch->type->value,
            'label' => $punch->type->short(),
            'time' => $local->format('g:i a'),
            'local' => $local->format('Y-m-d\TH:i'),
            'source' => $punch->source->value,
            'source_label' => $punch->source->label(),
            'geofence' => $punch->geofence,
            'location' => $punch->location?->name,
            'photo_url' => Files::url($punch->photo_path),
            'adjusted' => $punch->adjustment_id !== null,
            'sent_late' => $punch->sentLate(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adjustment(TimeAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->action->value,
            'label' => $a->action->label(),
            'description' => $this->describe($a),
            'reason' => $a->reason,
            'by' => $a->user->name ?? 'A manager',
            'at' => $a->created_at?->setTimezone($this->timezone)->format('M j, g:i a'),
            'disputed' => $a->disputed_at !== null,
            'open_dispute' => $a->isOpenDispute(),
            'dispute_note' => $a->dispute_note,
            'resolved' => $a->resolved_at !== null,
        ];
    }

    /**
     * "Moved clock out from 4:10 pm to 5:00 pm".
     */
    public function describe(TimeAdjustment $a): string
    {
        $time = fn (?CarbonImmutable $at): string => $at?->setTimezone($this->timezone)->format('D M j, g:i a') ?? '';
        $what = strtolower($a->punch_type?->label() ?? 'punch');

        return match ($a->action) {
            AdjustmentAction::Add => "Added {$what} at {$time($a->new_at)}",
            AdjustmentAction::Change => "Moved {$what} from {$time($a->old_at)} to {$time($a->new_at)}",
            AdjustmentAction::Remove => "Removed {$what} at {$time($a->old_at)}",
            AdjustmentAction::Hours => sprintf(
                '%s %s of %s on %s',
                (int) $a->minutes >= 0 ? 'Added' : 'Took off',
                TimesheetCalculator::hours(abs((int) $a->minutes)),
                $a->overtime ? 'overtime' : 'regular time',
                $a->work_date?->format('D M j') ?? '',
            ),
        };
    }
}
