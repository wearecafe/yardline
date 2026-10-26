import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import QuickBooksController from '@/actions/App/Http/Controllers/Settings/QuickBooksController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Field, NativeSelect } from '@/components/yard/field';
import { formatDate } from '@/lib/format';

type Slot = { key: string; label: string; types: string[]; default: string };
type Account = { id: string; name: string; type: string };

type Props = {
    configured: boolean;
    demo: boolean;
    integration: {
        status: 'connected' | 'paused' | 'error';
        company: string | null;
        realm_id: string;
        start_date: string | null;
        connected_at: string | null;
        last_error: string | null;
        sandbox: boolean;
    } | null;
    mapping: Record<string, string | null>;
    slots: Slot[];
    accounts: Account[];
    accountsError: string | null;
};

export default function QuickBooks({
    configured,
    demo,
    integration,
    mapping,
    slots,
    accounts,
    accountsError,
}: Props) {
    const form = useForm({
        start_date: integration?.start_date ?? '',
        accounts: mapping as Record<string, string | null>,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(QuickBooksController.update.url(), { preserveScroll: true });
    }

    const unmapped = slots.filter(
        (slot) => !form.data.accounts[slot.key],
    ).length;

    return (
        <>
            <Head title="QuickBooks" />
            <h1 className="sr-only">QuickBooks settings</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="QuickBooks Online"
                    description="Invoices, payments, credit memos and rental invoices are sent to QuickBooks as they happen. QuickBooks stays your general ledger."
                />

                {demo ? (
                    <p className="rounded-md border border-border bg-card p-4 text-sm text-muted-foreground">
                        The demo yard can’t connect to QuickBooks.
                    </p>
                ) : !configured ? (
                    <p className="rounded-md border border-border bg-card p-4 text-sm text-muted-foreground">
                        QuickBooks isn’t set up on this server yet. Add the
                        Intuit app’s client ID and secret (QUICKBOOKS_CLIENT_ID,
                        QUICKBOOKS_CLIENT_SECRET) to the environment.
                    </p>
                ) : integration === null ? (
                    <div className="rounded-md border border-border bg-card p-4 text-sm">
                        <p className="text-muted-foreground">
                            You’ll sign in to Intuit and pick the company.
                            Yardline only writes customers, items, invoices,
                            payments, credit memos and refunds; it never deletes
                            anything in QuickBooks.
                        </p>
                        <Button
                            className="mt-4"
                            onClick={() =>
                                router.post(QuickBooksController.connect.url())
                            }
                        >
                            Connect to QuickBooks
                        </Button>
                    </div>
                ) : (
                    <>
                        <div className="rounded-md border border-border bg-card p-4 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {integration.company ??
                                        `Company ${integration.realm_id}`}
                                </span>
                                <Chip
                                    tone={
                                        integration.status === 'connected'
                                            ? 'success'
                                            : 'danger'
                                    }
                                >
                                    {integration.status === 'connected'
                                        ? 'Connected'
                                        : 'Needs attention'}
                                </Chip>
                                {integration.sandbox && (
                                    <Chip tone="warning">Sandbox</Chip>
                                )}
                            </div>
                            {integration.connected_at && (
                                <div className="mt-1 text-muted-foreground">
                                    Connected{' '}
                                    {formatDate(integration.connected_at)}.
                                </div>
                            )}
                            {integration.last_error && (
                                <p className="mt-2 text-danger">
                                    {integration.last_error}
                                </p>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                className="mt-3"
                                onClick={() => {
                                    if (
                                        confirm(
                                            'Disconnect QuickBooks? Everything already sent stays in QuickBooks.',
                                        )
                                    ) {
                                        router.delete(
                                            QuickBooksController.destroy.url(),
                                        );
                                    }
                                }}
                            >
                                Disconnect
                            </Button>
                        </div>

                        <form onSubmit={submit} className="grid gap-6">
                            <Field
                                label="Send records dated from"
                                htmlFor="start_date"
                                error={form.errors.start_date}
                                hint="Nothing dated before this is sent, so history you’ve already entered in QuickBooks isn’t duplicated."
                            >
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={form.data.start_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'start_date',
                                            e.target.value,
                                        )
                                    }
                                    required
                                    className="max-w-48"
                                />
                            </Field>

                            <div className="grid gap-4">
                                <div>
                                    <div className="font-medium">Accounts</div>
                                    <p className="text-sm text-muted-foreground">
                                        Where each kind of line and payment
                                        posts in QuickBooks.
                                        {unmapped > 0 &&
                                            ` ${unmapped} still to choose; nothing is sent until all are set.`}
                                    </p>
                                    {accountsError && (
                                        <p className="mt-2 text-sm text-danger">
                                            {accountsError}
                                        </p>
                                    )}
                                </div>
                                {slots.map((slot) => {
                                    const options = accounts.filter((a) =>
                                        slot.types.includes(a.type),
                                    );

                                    return (
                                        <Field
                                            key={slot.key}
                                            label={slot.label}
                                            htmlFor={`account-${slot.key}`}
                                            error={
                                                form.errors[
                                                    `accounts.${slot.key}` as keyof typeof form.errors
                                                ]
                                            }
                                            hint={`${slot.types.join(' or ')} account, e.g. “${slot.default}”.`}
                                        >
                                            <NativeSelect
                                                id={`account-${slot.key}`}
                                                value={
                                                    form.data.accounts[
                                                        slot.key
                                                    ] ?? ''
                                                }
                                                onChange={(e) =>
                                                    form.setData('accounts', {
                                                        ...form.data.accounts,
                                                        [slot.key]:
                                                            e.target.value ||
                                                            null,
                                                    })
                                                }
                                                className="h-11"
                                            >
                                                <option value="">
                                                    Choose an account
                                                </option>
                                                {options.map((account) => (
                                                    <option
                                                        key={account.id}
                                                        value={account.id}
                                                    >
                                                        {account.name}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </Field>
                                    );
                                })}
                            </div>

                            <div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    Save
                                </Button>
                            </div>
                        </form>
                    </>
                )}
            </div>
        </>
    );
}

QuickBooks.layout = {
    breadcrumbs: [{ title: 'QuickBooks', href: QuickBooksController.edit() }],
};
