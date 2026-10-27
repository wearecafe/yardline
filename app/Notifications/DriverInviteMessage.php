<?php

namespace App\Notifications;

use App\Email\BrandedMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when the yard adds a driver: a link to choose a password, then
 * their run is on their phone.
 */
class DriverInviteMessage extends Notification
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
        return BrandedMail::user($this->business, "You're getting this because {$this->business} added you as a driver.")
            ->subject("Your driver sign-in for {$this->business}")
            ->title('Your driver sign-in is ready')
            ->greeting('Hello')
            ->line("{$this->business} has set you up to see your delivery run on your phone.")
            ->line('Choose a password to get started. The link works for 60 minutes; ask the yard for a new one if it runs out.')
            ->action('Choose a password', $this->url)
            ->line('Once you’re in, add the page to your home screen so your run is one tap away.')
            ->salutation($this->business);
    }
}
