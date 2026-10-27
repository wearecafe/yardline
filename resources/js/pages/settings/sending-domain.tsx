import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import SendingDomainController from '@/actions/App/Http/Controllers/Settings/SendingDomainController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { Checkbox, Field } from '@/components/yard/field';
import { formatAgo } from '@/lib/format';

type DnsRecord = {
    key: string;
    label: string;
    note: string;
    type: string;
    host: string;
    value: string;
    found: boolean;
    optional: boolean;
};

type Group = {
    value: string;
    label: string;
    description: string;
    suggested: string;
};

type Props = {
    domain: {
        domain: string;
        status: 'pending' | 'partial' | 'verified' | 'failing' | 'expired';
        status_label: string;
        records: DnsRecord[];
        mailboxes: Record<string, string>;
        sending_enabled: boolean;
        is_sending: boolean;
        last_checked_at: string | null;
        checks_until: string | null;
    } | null;
    groups: Group[];
    fallback: string;
};

const TONES: Record<string, ChipTone> = {
    verified: 'success',
    partial: 'warning',
    failing: 'danger',
    expired: 'danger',
    pending: 'neutral',
};

function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 px-2 text-xs"
            aria-label={`Copy ${label}`}
            onClick={() => {
                void navigator.clipboard?.writeText(value).then(() => {
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1500);
                });
            }}
        >
            {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}

function Connect({ fallback }: { fallback: string }) {
    const form = useForm({ domain: '', mailbox: 'hello' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(SendingDomainController.store.url(), {
            preserveScroll: true,
        });
    }

    return (
        <form onSubmit={submit} className="grid max-w-xl gap-6">
            <p className="text-sm text-muted-foreground">
                Customer emails go from {fallback} with your business name until
                you connect your own domain. Then quotes, invoices and delivery
                updates come from your own addresses.
            </p>
            <Field
                label="Your domain"
                htmlFor="domain"
                error={form.errors.domain}
                hint="The part after the @ in your business email, like youryard.ca."
            >
                <Input
                    id="domain"
                    value={form.data.domain}
                    placeholder="youryard.ca"
                    autoCapitalize="none"
                    onChange={(e) => form.setData('domain', e.target.value)}
                    required
                />
            </Field>
            <Field
                label="Main mailbox"
                htmlFor="mailbox"
                error={form.errors.mailbox}
                hint="Customer emails come from this address unless you give a kind of email its own mailbox."
            >
                <div className="flex items-center gap-2">
                    <Input
                        id="mailbox"
                        value={form.data.mailbox}
                        className="w-40"
                        onChange={(e) =>
                            form.setData('mailbox', e.target.value)
                        }
                        required
                    />
                    <span className="text-sm text-muted-foreground">
                        @{form.data.domain || 'youryard.ca'}
                    </span>
                </div>
            </Field>
            <div>
                <Button type="submit" disabled={form.processing}>
                    Connect domain
                </Button>
            </div>
        </form>
    );
}

function Records({ domain }: { domain: NonNullable<Props['domain']> }) {
    const [email, setEmail] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | undefined>();

    return (
        <section className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-lg font-semibold">{domain.domain}</h2>
                    <Chip tone={TONES[domain.status] ?? 'neutral'}>
                        {domain.status_label}
                    </Chip>
                </div>
                <span className="text-sm text-muted-foreground">
                    {domain.last_checked_at
                        ? `Checked ${formatAgo(domain.last_checked_at)}`
                        : 'Not checked yet'}
                    {['pending', 'partial'].includes(domain.status) &&
                        ' · we check every 10 minutes'}
                </span>
            </div>

            {domain.status === 'failing' && (
                <p className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900">
                    A record has gone missing, so customer emails are going out
                    from YardRunner’s address until it’s back.
                </p>
            )}
            {domain.status === 'expired' && (
                <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    We stopped checking automatically after 72 hours. Add the
                    records, then press Check now.
                </p>
            )}

            {domain.status !== 'verified' && (
                <p className="text-sm text-muted-foreground">
                    Add these records where your domain’s DNS is managed (often
                    the company you bought the domain from). Most DNS providers
                    add the domain to the host for you.
                </p>
            )}

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[720px] text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="px-3 py-2 font-medium">Record</th>
                            <th className="px-3 py-2 font-medium">Type</th>
                            <th className="px-3 py-2 font-medium">Host</th>
                            <th className="px-3 py-2 font-medium">Points to</th>
                            <th className="px-3 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {domain.records.map((record) => (
                            <tr key={record.key} className="border-t">
                                <td className="px-3 py-2.5">
                                    <div className="font-medium">
                                        {record.label}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {record.note}
                                    </div>
                                </td>
                                <td className="px-3 py-2.5">{record.type}</td>
                                <td className="px-3 py-2.5">
                                    <span className="flex items-center gap-2 font-mono text-xs">
                                        {record.host}
                                        <CopyButton
                                            value={record.host}
                                            label={`${record.label} host`}
                                        />
                                    </span>
                                </td>
                                <td className="px-3 py-2.5">
                                    <span className="flex items-center gap-2 font-mono text-xs break-all">
                                        {record.value}
                                        <CopyButton
                                            value={record.value}
                                            label={`${record.label} value`}
                                        />
                                    </span>
                                </td>
                                <td className="px-3 py-2.5">
                                    <Chip
                                        tone={
                                            record.found ? 'success' : 'neutral'
                                        }
                                    >
                                        {record.found
                                            ? 'Found'
                                            : record.optional
                                              ? 'Optional'
                                              : 'Not found yet'}
                                    </Chip>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            SendingDomainController.check.url(),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Check now
                </Button>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setSending(true);
                        router.post(
                            SendingDomainController.sendRecords.url(),
                            { email },
                            {
                                preserveScroll: true,
                                onError: (errors) => setError(errors.email),
                                onSuccess: () => {
                                    setEmail('');
                                    setError(undefined);
                                },
                                onFinish: () => setSending(false),
                            },
                        );
                    }}
                >
                    <Field
                        label="Send these to my IT person"
                        htmlFor="it_email"
                        error={error}
                    >
                        <Input
                            id="it_email"
                            type="email"
                            value={email}
                            placeholder="it@youryard.ca"
                            onChange={(e) => setEmail(e.target.value)}
                            className="w-60"
                            required
                        />
                    </Field>
                    <Button type="submit" variant="outline" disabled={sending}>
                        Send records
                    </Button>
                </form>
            </div>
        </section>
    );
}

function Mailboxes({
    domain,
    groups,
}: {
    domain: NonNullable<Props['domain']>;
    groups: Group[];
}) {
    const form = useForm({
        mailboxes: Object.fromEntries(
            groups.map((g) => [g.value, domain.mailboxes[g.value] ?? '']),
        ) as Record<string, string>,
        sending_enabled: domain.sending_enabled,
    });
    const verified = domain.status === 'verified';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(SendingDomainController.update.url(), {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    }

    return (
        <form onSubmit={submit} className="grid gap-5">
            <div>
                <h2 className="text-sm font-semibold">Mailboxes</h2>
                <p className="text-xs text-muted-foreground">
                    Which address each kind of customer email comes from. Leave
                    one blank to use your main mailbox. Replies go to the same
                    address unless you’ve set a reply-to address under Business.
                </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                {groups.map((group) => (
                    <Field
                        key={group.value}
                        label={group.label}
                        htmlFor={`mailbox-${group.value}`}
                        hint={group.description}
                        error={
                            (form.errors as Record<string, string>)[
                                `mailboxes.${group.value}`
                            ]
                        }
                    >
                        <div className="flex items-center gap-2">
                            <Input
                                id={`mailbox-${group.value}`}
                                value={form.data.mailboxes[group.value]}
                                placeholder={
                                    group.value === 'main'
                                        ? group.suggested
                                        : 'Use main'
                                }
                                className="w-36"
                                onChange={(e) =>
                                    form.setData('mailboxes', {
                                        ...form.data.mailboxes,
                                        [group.value]: e.target.value,
                                    })
                                }
                                required={group.value === 'main'}
                            />
                            <span className="truncate text-sm text-muted-foreground">
                                @{domain.domain}
                            </span>
                        </div>
                    </Field>
                ))}
            </div>
            <Checkbox
                id="sending_enabled"
                label="Send customer emails from this domain"
                checked={form.data.sending_enabled}
                disabled={!verified}
                onChange={(on) => form.setData('sending_enabled', on)}
                hint={
                    verified
                        ? undefined
                        : 'Available once all the required records are found.'
                }
            />
            <InputError message={form.errors.sending_enabled} />
            <div>
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                >
                    Save
                </Button>
            </div>
        </form>
    );
}

export default function SendingDomain({ domain, groups, fallback }: Props) {
    return (
        <>
            <Head title="Sending domain" />
            <h1 className="sr-only">Sending domain</h1>
            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Sending domain"
                    description="Send customer emails from your own addresses instead of YardRunner’s."
                />
                {domain === null ? (
                    <Connect fallback={fallback} />
                ) : (
                    <>
                        <Records domain={domain} />
                        <Mailboxes domain={domain} groups={groups} />
                        <section className="grid gap-2 border-t pt-6">
                            <h2 className="text-sm font-semibold">
                                Remove this domain
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Customer emails go back to {fallback}. You can
                                remove the DNS records afterwards.
                            </p>
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                `Remove ${domain.domain}? Customer emails will come from ${fallback} again.`,
                                            )
                                        ) {
                                            router.delete(
                                                SendingDomainController.destroy.url(),
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    Remove domain
                                </Button>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

SendingDomain.layout = {
    breadcrumbs: [
        { title: 'Sending domain', href: SendingDomainController.edit() },
    ],
};
