<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WebPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An alert on a staff member's phone or tablet. The text is worked out
 * when it's raised (see App\Alerts\StaffAlerts), so the queued send needs
 * nothing else from the database.
 */
class StaffAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $kind  order_to_pick | check_needed | check_rejected | run_assigned | stop_added | rental_overdue | test
     */
    public function __construct(public readonly string $kind, public readonly WebPushMessage $message)
    {
        $this->afterCommit();
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->message;
    }
}
