<?php

namespace App\Email\Domains;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publishes sending-domain records on the yardrunner.ca zone through
 * Cloudflare's DNS API (yardline.email.cloudflare).
 */
final class CloudflareDnsZone implements DnsZone
{
    public function put(string $name, array $records): void
    {
        $this->remove($name);

        foreach ($records as $record) {
            $body = [
                'type' => $record['type'],
                'name' => $name,
                'content' => $record['value'],
                'ttl' => 300,
                'proxied' => false,
            ];

            if ($record['type'] === 'MX') {
                $body['priority'] = (int) ($record['priority'] ?? 10);
            }

            $this->http()->post('dns_records', $body)->throw();
        }
    }

    public function remove(string $name): void
    {
        $existing = $this->http()->get('dns_records', ['name' => $name, 'per_page' => 100])->throw()->json('result', []);

        foreach ((array) $existing as $record) {
            if (is_array($record) && isset($record['id'])) {
                $this->http()->delete('dns_records/'.$record['id'])->throw();
            }
        }
    }

    private function http(): PendingRequest
    {
        $token = (string) config('yardline.email.cloudflare.token');
        $zone = (string) config('yardline.email.cloudflare.zone_id');

        if ($token === '' || $zone === '') {
            throw new RuntimeException('Set YARDLINE_DNS_CLOUDFLARE_TOKEN and YARDLINE_DNS_CLOUDFLARE_ZONE to publish sending-domain records.');
        }

        return Http::baseUrl("https://api.cloudflare.com/client/v4/zones/{$zone}/")
            ->withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 500, throw: false);
    }
}
