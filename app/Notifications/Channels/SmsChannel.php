<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Text messages. Notifications add 'sms' to via() and implement toSms();
 * notifiables answer routeNotificationForSms() with a phone number.
 */
class SmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $to = method_exists($notifiable, 'routeNotificationForSms') ? $notifiable->routeNotificationForSms() : null;

        if (blank($to) || ! method_exists($notification, 'toSms')) {
            return;
        }

        /** @var SmsMessage $message */
        $message = $notification->toSms($notifiable);

        if (config('yardrunner.sms.driver') !== 'twilio') {
            Log::info('SMS (not sent: log driver)', ['to' => $to, 'body' => $message->body]);

            return;
        }

        $sid = (string) config('yardrunner.sms.twilio.sid');

        Http::asForm()
            ->withBasicAuth($sid, (string) config('yardrunner.sms.twilio.token'))
            ->timeout(10)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => self::e164((string) $to),
                'From' => (string) config('yardrunner.sms.twilio.from'),
                'Body' => $message->body,
            ])
            ->throw();
    }

    /**
     * North American numbers to E.164 (+1XXXXXXXXXX); others left alone.
     */
    public static function e164(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return match (true) {
            str_starts_with($phone, '+') => '+'.$digits,
            strlen($digits) === 10 => '+1'.$digits,
            strlen($digits) === 11 && str_starts_with($digits, '1') => '+'.$digits,
            default => $phone,
        };
    }
}
