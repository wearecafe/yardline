<?php

namespace App\Email\Domains;

use Illuminate\Support\Str;

/**
 * Local development, tests and the demo yard: hands back records shaped
 * like the real service's, and treats a domain as verified once its
 * records are found (see SendingDomains::check).
 */
final class FakeDomainProvider implements DomainProvider
{
    /** Tests set this to say whether the service would pass the domain. */
    public static bool $verifies = true;

    public function create(string $domain, string $returnPath): array
    {
        return [
            'id' => 'fake_'.Str::lower(Str::random(12)),
            'records' => [
                ['type' => 'TXT', 'name' => 'resend._domainkey', 'value' => 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDfake', 'priority' => null],
                ['type' => 'MX', 'name' => $returnPath, 'value' => 'feedback-smtp.example.net', 'priority' => 10],
                ['type' => 'TXT', 'name' => $returnPath, 'value' => 'v=spf1 include:example.net ~all', 'priority' => null],
            ],
        ];
    }

    public function verify(string $id): bool
    {
        return self::$verifies;
    }

    public function delete(string $id): void {}
}
