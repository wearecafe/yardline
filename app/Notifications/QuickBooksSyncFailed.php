<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuickBooksSyncFailed extends Notification
{
    public function __construct(public string $error) {}

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
            ->subject('Something didn’t reach QuickBooks')
            ->line('Yardline couldn’t send a record to QuickBooks after trying for a day.')
            ->line("QuickBooks said: {$this->error}")
            ->action('Open the sync log', route('quickbooks.edit'))
            ->line('Fix the cause in QuickBooks or Yardline, then press “Send again”.');
    }
}
