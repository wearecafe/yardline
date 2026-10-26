<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StatementMessage extends Notification
{
    /**
     * @param  array<string, mixed>  $statement
     */
    public function __construct(public array $statement, public string $business, public string $pdf) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->statement['organization']['name'];

        return (new MailMessage)
            ->subject("Statement from {$this->business}")
            ->greeting('Hello')
            ->line("Your statement for {$name} is attached. The balance due is {$this->statement['balance']}.")
            ->line('Questions about an invoice? Reply to this email.')
            ->attachData($this->pdf, 'Statement.pdf', ['mime' => 'application/pdf'])
            ->salutation($this->business);
    }
}
