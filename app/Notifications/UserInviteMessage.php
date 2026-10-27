<?php

namespace App\Notifications;

use App\Email\BrandedMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when the owner adds someone under Settings → Users: a link to
 * choose a password and sign in to the yard.
 */
class UserInviteMessage extends Notification
{
    public function __construct(public string $url, public string $business, public string $role) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return BrandedMail::user($this->business, "You're getting this because {$this->business} added you to YardRunner.")
            ->subject("You've been added to {$this->business} on YardRunner")
            ->title("Join {$this->business} on YardRunner")
            ->greeting('Hello')
            ->line("{$this->business} has added you to YardRunner as {$this->role}.")
            ->line('Choose a password to get started. The link works for 60 minutes; ask the owner for a new one if it runs out.')
            ->action('Choose a password', $this->url)
            ->salutation($this->business);
    }
}
