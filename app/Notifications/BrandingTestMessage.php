<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Send me a test email" on Settings → Branding: a sample customer email,
 * from the address customer emails really use.
 */
class BrandingTestMessage extends Notification
{
    public function __construct(public MailMessage $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message;
    }
}
