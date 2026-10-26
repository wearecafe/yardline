<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteMessage extends Notification
{
    public function __construct(public Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $expires = $order->quote_expires_on?->format('F j, Y');

        return (new MailMessage)
            ->subject("Quote {$order->number} from {$order->tenant->name}")
            ->greeting('Hello')
            ->line("Here's your quote for ".Money::format($order->total_cents).'.'.($expires ? " Prices hold until {$expires}." : ''))
            ->action('View the quote', route('quotes.public', (string) $order->quote_token))
            ->line('Reply to this email or call the yard with any changes.')
            ->salutation($order->tenant->name);
    }
}
