<?php

namespace App\Notifications;

use App\Fulfillment\Dispatch;
use App\Models\Order;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Stop;
use App\Notifications\Channels\SmsMessage;
use Illuminate\Notifications\Notification;

class EtaMessage extends Notification
{
    public function __construct(public Stop $stop) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [Channels\SmsChannel::class];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $stop = $this->stop;
        $tz = $stop->tenant->timezone ?? 'America/Toronto';
        $start = $stop->eta_at ?? app(Dispatch::class)->plannedStart($stop);
        $window = $start !== null
            ? $start->setTimezone($tz)->format('g:i a').' to '.$start->setTimezone($tz)->addHours(2)->format('g:i a')
            : 'today';

        $what = match (true) {
            $stop->stoppable instanceof Shipment => "your order {$stop->stoppable->order->number}",
            $stop->stoppable instanceof Order => "your order {$stop->stoppable->number}",
            $stop->stoppable instanceof Rental => ($stop->kind === 'rental_pickup' ? 'pickup of ' : '')."rental {$stop->stoppable->number}",
            default => 'your delivery',
        };

        return new SmsMessage("{$stop->tenant->name}: {$what} is scheduled for {$window}. Reply or call the yard with any questions.");
    }
}
