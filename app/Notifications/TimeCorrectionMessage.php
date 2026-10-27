<?php

namespace App\Notifications;

use App\Enums\AdjustmentAction;
use App\Models\TimeAdjustment;
use App\Time\TimesheetCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone a manager changed their time, and why. They can see it
 * on their timesheet and dispute it there.
 */
class TimeCorrectionMessage extends Notification
{
    public function __construct(public TimeAdjustment $adjustment, public string $timezone, public string $business) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your hours were changed at {$this->business}")
            ->greeting('Hello')
            ->line($this->describe())
            ->line('Reason: '.$this->adjustment->reason)
            ->line('If this doesn’t look right, open your timesheet and dispute it before payroll.')
            ->action('Open my timesheet', route('time.self.show'))
            ->salutation($this->business);
    }

    public function describe(): string
    {
        $a = $this->adjustment;
        $fmt = fn (?CarbonImmutable $at): string => $at?->setTimezone($this->timezone)->format('D M j, g:i a') ?? '';
        $by = $a->user->name ?? 'A manager';
        $what = strtolower($a->punch_type?->label() ?? 'punch');

        return match ($a->action) {
            AdjustmentAction::Add => "{$by} added a {$what} at {$fmt($a->new_at)}.",
            AdjustmentAction::Change => "{$by} moved your {$what} from {$fmt($a->old_at)} to {$fmt($a->new_at)}.",
            AdjustmentAction::Remove => "{$by} removed your {$what} at {$fmt($a->old_at)}.",
            AdjustmentAction::Hours => sprintf(
                '%s %s %s %s on %s.',
                $by,
                (int) $a->minutes >= 0 ? 'added' : 'took off',
                TimesheetCalculator::hours(abs((int) $a->minutes)),
                $a->overtime ? 'of overtime' : 'of regular time',
                $a->work_date?->format('D M j') ?? '',
            ),
        };
    }
}
