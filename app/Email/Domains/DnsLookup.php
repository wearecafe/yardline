<?php

namespace App\Email\Domains;

/**
 * Reads public DNS, to show the yard which of its records are in place.
 * Tests swap in fixed answers with fake().
 */
class DnsLookup
{
    /** @var array<string, list<string>>|null */
    private static ?array $fake = null;

    /**
     * @param  array<string, list<string>>  $answers  "TYPE name" => values, e.g. "CNAME yr.example.ca" => ["bounce-1.dns.yardrunner.ca"]
     */
    public static function fake(array $answers): void
    {
        self::$fake = $answers;
    }

    public static function forget(): void
    {
        self::$fake = null;
    }

    /**
     * @return list<string> lower case, without trailing dots
     */
    public function values(string $type, string $name): array
    {
        $type = strtoupper($type);
        $name = rtrim(strtolower($name), '.');

        if (self::$fake !== null) {
            return array_map(fn (string $v) => rtrim(strtolower($v), '.'), self::$fake["{$type} {$name}"] ?? []);
        }

        $constant = match ($type) {
            'CNAME' => DNS_CNAME,
            'TXT' => DNS_TXT,
            'MX' => DNS_MX,
            default => DNS_ANY,
        };

        $answers = @dns_get_record($name, $constant);

        if (! is_array($answers)) {
            return [];
        }

        $values = [];

        foreach ($answers as $answer) {
            $value = match ($type) {
                'CNAME' => $answer['target'] ?? null,
                'TXT' => $answer['txt'] ?? null,
                'MX' => $answer['target'] ?? null,
                default => null,
            };

            if (is_string($value)) {
                $values[] = rtrim(strtolower($value), '.');
            }
        }

        return $values;
    }
}
