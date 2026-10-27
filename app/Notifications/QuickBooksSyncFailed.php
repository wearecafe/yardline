<?php

namespace App\Notifications;

use App\Email\BrandedMail;
use App\Tenancy\CurrentTenant;
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
        return BrandedMail::user(CurrentTenant::get()?->name, "You're getting this because you look after QuickBooks syncing.")
            ->subject('Something didn’t reach QuickBooks')
            ->notice('Sync failed', 'danger')
            ->line('YardRunner couldn’t send a record to QuickBooks after trying for a day.')
            ->line("QuickBooks said: {$this->error}")
            ->action('Open the sync log', route('quickbooks.edit'))
            ->line('Fix the cause in QuickBooks or YardRunner, then press “Send again”.');
    }
}
