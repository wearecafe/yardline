<?php

namespace App\Rentals\Notifications;

use App\Models\Rental;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\SmsMessage;
use App\Support\Files;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RentalMessage extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public Rental $rental,
        public string $kind,
        public string $subject,
        public string $text,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public ?string $attachmentPath = null,
        public array $channels = ['mail', 'sms'],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_map(
            fn (string $channel) => $channel === 'sms' ? SmsChannel::class : $channel,
            $this->channels,
        ));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello')
            ->line($this->text);

        if ($this->actionUrl !== null) {
            $mail->action($this->actionLabel ?? 'Open', $this->actionUrl);
        }

        if ($this->attachmentPath !== null && Files::disk()->exists($this->attachmentPath)) {
            $mail->attachData((string) Files::disk()->get($this->attachmentPath), "Rental agreement {$this->rental->number}.pdf", [
                'mime' => 'application/pdf',
            ]);
        }

        return $mail->salutation($this->rental->tenant->name);
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $body = $this->rental->tenant->name.': '.$this->text;

        if ($this->actionUrl !== null) {
            $body .= ' '.$this->actionUrl;
        }

        return new SmsMessage($body);
    }
}
