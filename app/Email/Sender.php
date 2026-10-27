<?php

namespace App\Email;

use App\Models\SendingDomain;
use App\Models\Tenant;

/**
 * Who a customer email is from. A yard with a verified domain and sending
 * switched on uses its own mailboxes; otherwise it's YardRunner's address
 * under the yard's name. Replies go to the yard's reply-to address when
 * it has set one.
 */
final readonly class Sender
{
    public function __construct(
        public string $address,
        public string $name,
        public ?string $replyTo,
        public bool $ownDomain,
    ) {}

    public static function forCustomer(Tenant $tenant, MailGroup $group = MailGroup::Main): self
    {
        $domain = SendingDomain::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if ($domain !== null && $domain->isSending()) {
            return new self($domain->address($group), $tenant->name, $tenant->replyToEmail(), true);
        }

        return new self(self::fallbackAddress($tenant), $tenant->name, $tenant->replyToEmail(), false);
    }

    /**
     * YardRunner's address for a yard without its own domain: the yard's
     * slug at the shared sending domain when one is set, else the app's
     * from address.
     */
    public static function fallbackAddress(Tenant $tenant): string
    {
        $shared = (string) config('yardline.email.tenant_from_domain');

        return $shared !== '' ? "{$tenant->slug}@{$shared}" : (string) config('mail.from.address');
    }

    /**
     * YardRunner's own address, for emails to the tenant's users.
     */
    public static function yardRunner(): self
    {
        return new self((string) config('mail.from.address'), 'YardRunner', null, false);
    }
}
