<?php

namespace App\Email\Domains;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The production email service's domains API. Uses the same API key as
 * outgoing mail. Its error text is never shown to a yard; callers report
 * the exception and show YardRunner's own message (see DomainProblem).
 */
final class ResendDomainProvider implements DomainProvider
{
    public function create(string $domain, string $returnPath): array
    {
        $response = $this->http()->post('domains', [
            'name' => $domain,
            'custom_return_path' => $returnPath,
            'open_tracking' => false,
            'click_tracking' => false,
        ]);

        if ($response->failed()) {
            throw new DomainProblem($this->reason($response->json('message'), $response->status()));
        }

        $records = [];

        foreach ((array) $response->json('records', []) as $record) {
            if (! is_array($record) || ! in_array($record['record'] ?? null, ['SPF', 'DKIM'], true)) {
                continue; // receiving and tracking records aren't used
            }

            $records[] = [
                'type' => strtoupper((string) ($record['type'] ?? 'TXT')),
                'name' => self::relative((string) ($record['name'] ?? ''), $domain),
                'value' => (string) ($record['value'] ?? ''),
                'priority' => isset($record['priority']) ? (int) $record['priority'] : null,
            ];
        }

        return ['id' => (string) $response->json('id'), 'records' => $records];
    }

    public function verify(string $id): bool
    {
        try {
            $this->http()->post("domains/{$id}/verify");
        } catch (Throwable $e) {
            report($e); // the status read below still says where things stand
        }

        $response = $this->http()->get("domains/{$id}");

        if ($response->failed()) {
            throw new DomainProblem($this->reason($response->json('message'), $response->status()));
        }

        return $response->json('status') === 'verified';
    }

    public function delete(string $id): void
    {
        $response = $this->http()->delete("domains/{$id}");

        if ($response->failed() && $response->status() !== 404) {
            throw new DomainProblem($this->reason($response->json('message'), $response->status()));
        }
    }

    /**
     * "send.example.com" or "send" → "send".
     */
    public static function relative(string $name, string $domain): string
    {
        $name = rtrim(strtolower($name), '.');
        $suffix = '.'.strtolower($domain);

        if (str_ends_with($name, $suffix)) {
            return substr($name, 0, -strlen($suffix));
        }

        return $name === strtolower($domain) ? '@' : $name;
    }

    private function http(): PendingRequest
    {
        $key = (string) config('services.resend.key');

        if ($key === '') {
            throw new RuntimeException('No email service API key is set (RESEND_API_KEY).');
        }

        return Http::baseUrl('https://api.resend.com/')
            ->withToken($key)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 500, throw: false);
    }

    /**
     * Logged for YardRunner staff; DomainProblem decides what a yard sees.
     */
    private function reason(mixed $message, int $status): string
    {
        return 'Email domain service returned '.$status.(is_string($message) ? ": {$message}" : '');
    }
}
