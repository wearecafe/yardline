<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Alerts on staff phones and tablets (web push). Notifications add this
 * channel to via() and implement toWebPush(); it goes to every device the
 * user turned alerts on for.
 *
 * Devices the push service says are gone (uninstalled, permission taken
 * back) are forgotten. With no VAPID keys set, alerts are logged instead.
 */
class WebPushChannel
{
    public static function configured(): bool
    {
        return filled(config('yardline.push.public_key')) && filled(config('yardline.push.private_key'));
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $devices = $notifiable->pushSubscriptions()->get();

        if ($devices->isEmpty()) {
            return;
        }

        /** @var WebPushMessage $message */
        $message = $notification->toWebPush($notifiable);

        if (! self::configured()) {
            Log::info('Push alert (not sent: no VAPID keys)', ['user' => $notifiable->id, 'title' => $message->title, 'body' => $message->body]);

            return;
        }

        $push = new WebPush([
            'VAPID' => [
                'subject' => (string) config('yardline.push.subject'),
                'publicKey' => (string) config('yardline.push.public_key'),
                'privateKey' => (string) config('yardline.push.private_key'),
            ],
        ], [
            'TTL' => $message->ttl,
            'urgency' => $message->urgency,
        ]);
        $push->setReuseVAPIDHeaders(true);

        foreach ($devices as $device) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $device->endpoint,
                'publicKey' => $device->public_key,
                'authToken' => $device->auth_token,
                'contentEncoding' => $device->content_encoding,
            ]), $message->payload());
        }

        foreach ($push->flush() as $report) {
            $hash = PushSubscription::hash($report->getEndpoint());

            if ($report->isSuccess()) {
                PushSubscription::query()->where('endpoint_hash', $hash)->update(['last_used_at' => now()]);
            } elseif ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint_hash', $hash)->delete();
            } else {
                Log::warning('Push alert failed', ['user' => $notifiable->id, 'reason' => $report->getReason()]);
            }
        }
    }
}
