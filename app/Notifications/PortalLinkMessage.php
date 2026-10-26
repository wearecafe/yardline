<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalLinkMessage extends Notification
{
    public function __construct(public string $url, public string $business) {}

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
            ->subject("Sign in to {$this->business}")
            ->greeting('Hello')
            ->line('Use this link to sign in to your contractor account. It works for 30 minutes.')
            ->action('Sign in', $this->url)
            ->line('If you didn’t ask for this, you can ignore it.')
            ->salutation($this->business);
    }
}
