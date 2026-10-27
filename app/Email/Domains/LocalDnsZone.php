<?php

namespace App\Email\Domains;

/**
 * Local development, tests and the demo yard: remembers what would be
 * published instead of changing real DNS.
 */
final class LocalDnsZone implements DnsZone
{
    /** @var array<string, list<array{type: string, value: string, priority?: int|null}>> */
    public static array $records = [];

    public function put(string $name, array $records): void
    {
        self::$records[$name] = $records;
    }

    public function remove(string $name): void
    {
        unset(self::$records[$name]);
    }
}
