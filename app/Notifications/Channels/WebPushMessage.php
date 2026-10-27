<?php

namespace App\Notifications\Channels;

/**
 * One alert on a staff device. Tapping it opens $url. Alerts with the same
 * tag replace each other on the device instead of stacking up.
 */
final readonly class WebPushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public ?string $tag = null,
        /** normal | high (high wakes a sleeping phone sooner) */
        public string $urgency = 'normal',
        /** Seconds the push service keeps it for a device that's offline. */
        public int $ttl = 3600,
    ) {}

    public function payload(): string
    {
        return (string) json_encode([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
