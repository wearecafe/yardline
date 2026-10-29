<?php

namespace App\Email\Domains;

/**
 * YardRunner's own DNS zone for sending domains (yardrunner.email.dns_zone).
 * The yard's records are CNAMEs pointing at names here, and the email
 * service's real records live under those names, so the yard's DNS never
 * names the service and the service can change without the yard's help.
 */
interface DnsZone
{
    /**
     * Replace every record at a name in the zone.
     *
     * @param  string  $name  fully qualified, inside the zone
     * @param  list<array{type: string, value: string, priority?: int|null}>  $records
     */
    public function put(string $name, array $records): void;

    public function remove(string $name): void;
}
