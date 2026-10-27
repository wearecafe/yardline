<?php

namespace App\Email\Domains;

/**
 * The email service that signs and sends customer emails for a yard's own
 * domain. Tenants never see which service this is: its records are
 * published on YardRunner's DNS and its errors are reworded before they
 * reach a screen.
 */
interface DomainProvider
{
    /**
     * Register a domain. Each record's `name` is relative to the domain
     * (e.g. "yr", "selector._domainkey").
     *
     * @return array{id: string, records: list<array{type: string, name: string, value: string, priority?: int|null}>}
     */
    public function create(string $domain, string $returnPath): array;

    /**
     * Ask the service to check the domain's DNS now, and say whether it can
     * send for it.
     */
    public function verify(string $id): bool;

    public function delete(string $id): void;
}
