<?php

namespace App\Models;

use App\Email\MailGroup;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A yard's own email domain. See the create_sending_domains_table
 * migration for what each column holds.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $domain
 * @property string $status pending | partial | verified | failing | expired
 * @property string|null $provider_id
 * @property list<array{key: string, label: string, note: string, type: string, host: string, value: string, found: bool, optional?: bool}>|null $records
 * @property list<array{key: string, type: string, name: string, value: string, priority?: int|null, zone_name: string}>|null $provider_records
 * @property array<string, string>|null $mailboxes
 * @property bool $sending_enabled
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $failing_since
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $checks_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['domain', 'status', 'provider_id', 'records', 'provider_records', 'mailboxes', 'sending_enabled', 'verified_at', 'failing_since', 'last_checked_at', 'checks_until'])]
#[Hidden(['provider_id', 'provider_records'])]
class SendingDomain extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PARTIAL = 'partial';

    public const VERIFIED = 'verified';

    public const FAILING = 'failing';

    public const EXPIRED = 'expired';

    /** How long YardRunner keeps checking for the records automatically. */
    public const CHECK_HOURS = 72;

    protected function casts(): array
    {
        return [
            'records' => 'array',
            'provider_records' => 'array',
            'mailboxes' => 'array',
            'sending_enabled' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'failing_since' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'checks_until' => 'immutable_datetime',
        ];
    }

    /**
     * Whether customer emails go out from this domain right now. A domain
     * that has stopped passing falls back to YardRunner's address.
     */
    public function isSending(): bool
    {
        return $this->sending_enabled && $this->status === self::VERIFIED;
    }

    /**
     * The address a mail group sends from, falling back to the main mailbox.
     */
    public function address(MailGroup $group): string
    {
        $mailboxes = $this->mailboxes ?? [];
        $local = $mailboxes[$group->value] ?? null;

        if (! is_string($local) || $local === '') {
            $local = $mailboxes[MailGroup::Main->value] ?? MailGroup::Main->suggested();
        }

        return "{$local}@{$this->domain}";
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::VERIFIED => 'Verified',
            self::PARTIAL => 'Partly verified',
            self::FAILING => 'Needs attention',
            self::EXPIRED => 'Not found after 72 hours',
            default => 'Waiting for DNS',
        };
    }
}
