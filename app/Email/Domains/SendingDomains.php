<?php

namespace App\Email\Domains;

use App\Audit\Audit;
use App\Email\MailGroup;
use App\Models\SendingDomain;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SendingDomainMessage;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Connecting a yard's own email domain, checking its DNS, and switching
 * customer emails over to it. The yard only ever sees CNAMEs pointing at
 * yardrunner.ca (plus an optional DMARC record); the email service's real
 * records are published under those names on YardRunner's zone.
 */
final class SendingDomains
{
    /** Subdomain the service uses for bounces (its return path). */
    public const RETURN_PATH = 'yr';

    /** Free mail domains a yard can't send as. */
    public const FREE_MAIL = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'yahoo.ca', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'sympatico.ca', 'rogers.com', 'bell.net', 'shaw.ca', 'telus.net', 'cogeco.ca'];

    public function __construct(
        private readonly DomainProvider $provider,
        private readonly DnsZone $zone,
        private readonly DnsLookup $dns,
    ) {}

    /**
     * Register the domain with the email service, publish its records on
     * YardRunner's zone, and work out the records the yard adds.
     */
    public function connect(Tenant $tenant, string $domain, string $mainMailbox): SendingDomain
    {
        $domain = strtolower(trim($domain, " .\t\n"));
        $created = $this->provider->create($domain, self::RETURN_PATH);

        $byHost = [];

        foreach ($created['records'] as $record) {
            $byHost[$record['name']][] = $record;
        }

        $records = [];
        $providerRecords = [];
        $zone = (string) config('yardrunner.email.dns_zone');

        foreach ($byHost as $host => $hostRecords) {
            $signing = str_contains($host, '_domainkey');
            $key = $signing ? 'dkim' : 'bounce';
            $n = count(array_filter($records, fn (array $r) => str_starts_with($r['key'], $key)));
            $key .= $n > 0 ? (string) ($n + 1) : '';
            $target = "{$key}-{$tenant->id}.{$zone}";

            $this->zone->put($target, array_map(fn (array $r) => [
                'type' => $r['type'],
                'value' => $r['value'],
                'priority' => $r['priority'] ?? null,
            ], $hostRecords));

            foreach ($hostRecords as $r) {
                $providerRecords[] = [...$r, 'key' => $key, 'zone_name' => $target];
            }

            $records[] = [
                'key' => $key,
                'label' => $signing ? 'Signing key' : 'Bounce handling',
                'note' => $signing ? 'Proves YardRunner may send email for you' : 'Handles returned and rejected mail',
                'type' => 'CNAME',
                'host' => $host,
                'value' => $target,
                'found' => false,
                'optional' => false,
            ];
        }

        $records[] = [
            'key' => 'dmarc',
            'label' => 'DMARC',
            'note' => 'Recommended. Skip it if your domain already has one.',
            'type' => 'TXT',
            'host' => '_dmarc',
            'value' => 'v=DMARC1; p=none;',
            'found' => false,
            'optional' => true,
        ];

        $sendingDomain = CurrentTenant::run($tenant, fn () => SendingDomain::query()->create([
            'domain' => $domain,
            'status' => SendingDomain::PENDING,
            'provider_id' => $created['id'],
            'records' => $records,
            'provider_records' => $providerRecords,
            'mailboxes' => [MailGroup::Main->value => $mainMailbox],
            'sending_enabled' => false,
            'checks_until' => CarbonImmutable::now()->addHours(SendingDomain::CHECK_HOURS),
        ]));

        CurrentTenant::run($tenant, fn () => Audit::record($tenant, 'sending_domain.connected', null, $domain));

        return $sendingDomain;
    }

    /**
     * Look the records up, ask the email service, and move the status on.
     * Owners and admins are emailed when the domain is verified and when a
     * verified domain stops passing.
     */
    public function check(SendingDomain $domain): SendingDomain
    {
        $records = $domain->records ?? [];

        foreach ($records as $i => $record) {
            $fqdn = "{$record['host']}.{$domain->domain}";
            $values = $this->dns->values($record['type'], $fqdn);

            $records[$i]['found'] = $record['key'] === 'dmarc'
                ? collect($values)->contains(fn (string $v) => str_starts_with(str_replace(' ', '', trim($v, '"')), 'v=dmarc1'))
                : in_array(strtolower($record['value']), $values, true);
        }

        $required = array_filter($records, fn (array $r) => ! ($r['optional'] ?? false));
        $allFound = $required !== [] && collect($required)->every(fn (array $r) => $r['found']);
        $anyFound = collect($required)->contains(fn (array $r) => $r['found']);

        $passes = false;

        if ($allFound && $domain->provider_id !== null) {
            try {
                $passes = $this->provider->verify($domain->provider_id);
            } catch (Throwable $e) {
                report($e);
                $passes = $domain->status === SendingDomain::VERIFIED; // don't flap on a service hiccup
            }
        }

        $before = $domain->status;
        $now = CarbonImmutable::now();

        $status = match (true) {
            $passes => SendingDomain::VERIFIED,
            in_array($before, [SendingDomain::VERIFIED, SendingDomain::FAILING], true) => SendingDomain::FAILING,
            $domain->checks_until !== null && $domain->checks_until->isPast() => SendingDomain::EXPIRED,
            $anyFound => SendingDomain::PARTIAL,
            default => SendingDomain::PENDING,
        };

        $domain->forceFill([
            'records' => $records,
            'status' => $status,
            'last_checked_at' => $now,
            'verified_at' => $status === SendingDomain::VERIFIED ? ($domain->verified_at ?? $now) : $domain->verified_at,
            'failing_since' => $status === SendingDomain::FAILING ? ($domain->failing_since ?? $now) : null,
        ])->save();

        if ($status !== $before && in_array($status, [SendingDomain::VERIFIED, SendingDomain::FAILING], true)) {
            $this->tell($domain, $status);
        }

        return $domain;
    }

    /**
     * Start checking again after the 72 hours ran out ("Check now").
     */
    public function restart(SendingDomain $domain): SendingDomain
    {
        if ($domain->status === SendingDomain::EXPIRED) {
            $domain->forceFill(['status' => SendingDomain::PENDING, 'checks_until' => CarbonImmutable::now()->addHours(SendingDomain::CHECK_HOURS)])->save();
        }

        return $this->check($domain);
    }

    public function remove(SendingDomain $domain): void
    {
        try {
            if ($domain->provider_id !== null) {
                $this->provider->delete($domain->provider_id);
            }

            foreach ($domain->records ?? [] as $record) {
                if ($record['type'] === 'CNAME') {
                    $this->zone->remove($record['value']);
                }
            }
        } catch (Throwable $e) {
            report($e); // the yard is done with it either way
        }

        $tenant = $domain->tenant;
        $name = $domain->domain;
        $domain->delete();

        if ($tenant !== null) {
            CurrentTenant::run($tenant, fn () => Audit::record($tenant, 'sending_domain.removed', null, $name));
        }
    }

    private function tell(SendingDomain $domain, string $status): void
    {
        $tenant = $domain->tenant;

        if ($tenant === null) {
            return;
        }

        CurrentTenant::run($tenant, function () use ($domain, $status, $tenant) {
            $people = User::query()->where('tenant_id', $tenant->id)->get()
                ->filter(fn (User $u) => $u->canSignIn() && $u->canManageBusiness());

            foreach ($people as $user) {
                try {
                    $user->notify(new SendingDomainMessage($domain, $status));
                } catch (Throwable $e) {
                    report($e);
                }
            }

            Audit::record($tenant, $status === SendingDomain::VERIFIED ? 'sending_domain.verified' : 'sending_domain.failing', null, $domain->domain);
        });
    }
}
