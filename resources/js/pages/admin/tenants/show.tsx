import { Head, Link, router, useForm } from '@inertiajs/react';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import {
    BarChart,
    Figure,
    HealthChip,
    TenantStatusChip,
} from '@/components/admin/parts';
import type { Health, TenantStatus } from '@/components/admin/parts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import {
    formatAgo,
    formatChange,
    formatDate,
    formatDateTime,
    formatWholeMoney,
} from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    tenant: {
        id: number;
        name: string;
        slug: string;
        status: TenantStatus;
        plan: string | null;
        plan_label: string | null;
        monthly_price_cents: number | null;
        trial_ends_at: string | null;
        billing: Billing;
        currency: string;
        timezone: string;
        created_at: string | null;
        last_active_at: string | null;
        status_changed_at: string | null;
        staff_notes: string | null;
        branding: { colour_system: boolean; sent_with_yardrunner: boolean };
        owner: { id: number; name: string; email: string } | null;
        health: Health;
    };
    stats: {
        orders: number;
        prior_orders: number;
        sales_cents: number;
        rentals: number;
        rentals_out: number;
        deliveries: number;
        stock_movements: number;
        weekly_users: number;
        users: number;
    };
    days: { date: string; orders: number }[];
    integrations: {
        name: string;
        state: string;
        tone: ChipTone;
        lines: string[];
    }[];
    yards: {
        id: number;
        name: string;
        code: string | null;
        is_default: boolean;
        orders: number;
    }[];
    users: {
        id: number;
        name: string;
        email: string;
        role: string;
        two_factor: boolean;
        joined: boolean;
        last_seen_at: string | null;
    }[];
    audit: {
        id: number;
        at: string;
        event: string;
        subject: string;
        note: string | null;
        who: string;
    }[];
};

type Billing = {
    status_label: string;
    has_access: boolean;
    subscribed: boolean;
    exempt: boolean;
    suspended: boolean;
    subscription_status: string | null;
    trial_editable: boolean;
    trial_days_left: number | null;
    period_ends_at: string | null;
    cancelling: boolean;
    billed_seats: number | null;
    lines: {
        key: string;
        name: string;
        unit_cents: number;
        quantity: number;
        per_user: boolean;
        total_cents: number;
        on: boolean;
    }[];
    total_cents: number;
    stripe_customer_url: string | null;
    stripe_subscription_url: string | null;
};

/** "2026-11-09" plus days, as "2026-11-xx". */
function addDays(date: string, days: number): string {
    const d = new Date(`${date}T12:00:00`);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

function Subscription({ tenant }: Pick<Props, 'tenant'>) {
    const billing = tenant.billing;
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({ trial_ends_at: tenant.trial_ends_at ?? today });
    const save = (trialEndsAt: string) =>
        router.patch(
            TenantController.update.url(tenant.id),
            { trial_ends_at: trialEndsAt },
            { preserveScroll: true, onSuccess: () => form.setDefaults() },
        );
    const extend = () => {
        const from =
            tenant.trial_ends_at && tenant.trial_ends_at > today
                ? tenant.trial_ends_at
                : today;
        save(addDays(from, 7));
    };
    const suspend = (on: boolean) => {
        if (
            on &&
            !window.confirm(
                `Suspend ${tenant.name}? Nobody there can use YardRunner until you restore them. Their Stripe subscription isn’t changed.`,
            )
        ) {
            return;
        }

        router.patch(
            TenantController.update.url(tenant.id),
            { suspended: on },
            { preserveScroll: true },
        );
    };

    if (billing.exempt) {
        return (
            <Section title="Subscription">
                <p className="text-sm text-muted-foreground">
                    The demo yard isn’t billed.
                </p>
            </Section>
        );
    }

    return (
        <Section
            title="Subscription"
            description="Follows their trial and Stripe. They switch add-ons on themselves under Settings → Billing."
        >
            <div className="grid gap-4 text-sm">
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                    <dt className="text-muted-foreground">Status</dt>
                    <dd>
                        {billing.status_label}
                        {billing.subscription_status &&
                            ` · Stripe: ${billing.subscription_status}`}
                        {!billing.has_access && ' · locked out'}
                    </dd>
                    {billing.trial_days_left !== null && (
                        <>
                            <dt className="text-muted-foreground">Trial</dt>
                            <dd>
                                {billing.trial_days_left === 1
                                    ? 'Last day'
                                    : `${billing.trial_days_left} days left`}
                                {billing.subscribed
                                    ? ', card on file'
                                    : ', no card yet'}
                            </dd>
                        </>
                    )}
                    {billing.period_ends_at && billing.subscribed && (
                        <>
                            <dt className="text-muted-foreground">
                                {billing.cancelling ? 'Ends' : 'Next bill'}
                            </dt>
                            <dd>{formatDate(billing.period_ends_at)}</dd>
                        </>
                    )}
                </dl>

                <ul className="-my-1 divide-y divide-secondary">
                    {billing.lines.map((line) => (
                        <li
                            key={line.key}
                            className={cn(
                                'flex min-h-10 items-center justify-between gap-3',
                                !line.on && 'text-muted-foreground',
                            )}
                        >
                            <span>
                                {line.name}
                                {line.per_user && line.on
                                    ? ` × ${line.quantity}`
                                    : ''}
                                {!line.on && ' (off)'}
                            </span>
                            <span className="figure">
                                {formatWholeMoney(
                                    line.total_cents,
                                    tenant.currency,
                                )}
                            </span>
                        </li>
                    ))}
                    <li className="flex min-h-10 items-center justify-between gap-3 font-medium">
                        <span>Monthly, before tax</span>
                        <span className="figure">
                            {formatWholeMoney(
                                billing.total_cents,
                                tenant.currency,
                            )}
                        </span>
                    </li>
                </ul>

                {billing.trial_editable && (
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            save(form.data.trial_ends_at);
                        }}
                    >
                        <Field
                            label="Last day of trial"
                            htmlFor="trial_ends_at"
                            error={form.errors.trial_ends_at}
                        >
                            <Input
                                id="trial_ends_at"
                                type="date"
                                value={form.data.trial_ends_at}
                                onChange={(e) =>
                                    form.setData(
                                        'trial_ends_at',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={!form.isDirty}
                            >
                                Save trial
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={extend}
                            >
                                Extend 7 days
                            </Button>
                        </div>
                        {billing.subscribed && (
                            <p className="text-xs text-muted-foreground">
                                Moves the trial end on their Stripe subscription
                                too.
                            </p>
                        )}
                    </form>
                )}

                <div className="flex flex-wrap gap-2">
                    {billing.stripe_customer_url && (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={billing.stripe_customer_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Customer in Stripe
                            </a>
                        </Button>
                    )}
                    {billing.stripe_subscription_url && (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={billing.stripe_subscription_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Subscription in Stripe
                            </a>
                        </Button>
                    )}
                    <Button
                        variant={billing.suspended ? 'outline' : 'ghost'}
                        size="sm"
                        onClick={() => suspend(!billing.suspended)}
                    >
                        {billing.suspended ? 'Restore access' : 'Suspend'}
                    </Button>
                </div>
            </div>
        </Section>
    );
}

function Branding({ tenant }: Pick<Props, 'tenant'>) {
    const set = (key: 'colour_system' | 'sent_with_yardrunner', on: boolean) =>
        router.patch(
            TenantController.update.url(tenant.id),
            { [key]: on },
            { preserveScroll: true },
        );

    return (
        <Section
            title="Email branding"
            description="Switches only staff can change. Each change goes in their audit log."
        >
            <div className="grid gap-4 text-sm">
                <label className="flex items-start gap-2.5">
                    <input
                        type="checkbox"
                        className="mt-0.5 size-4 rounded border-input accent-foreground"
                        checked={tenant.branding.colour_system}
                        onChange={(e) => set('colour_system', e.target.checked)}
                    />
                    <span className="grid gap-0.5">
                        <span className="font-medium">Full colour system</span>
                        <span className="text-xs text-muted-foreground">
                            Off by default. When on, they can change every email
                            colour under Settings → Branding, not just the
                            primary. Turn on only when they ask.
                        </span>
                    </span>
                </label>
                <label className="flex items-start gap-2.5">
                    <input
                        type="checkbox"
                        className="mt-0.5 size-4 rounded border-input accent-foreground"
                        checked={tenant.branding.sent_with_yardrunner}
                        onChange={(e) =>
                            set('sent_with_yardrunner', e.target.checked)
                        }
                    />
                    <span className="grid gap-0.5">
                        <span className="font-medium">
                            “Sent with YardRunner” footer
                        </span>
                        <span className="text-xs text-muted-foreground">
                            On by default, on emails to their customers.
                        </span>
                    </span>
                </label>
            </div>
        </Section>
    );
}

function Notes({ tenant }: Pick<Props, 'tenant'>) {
    const form = useForm({ staff_notes: tenant.staff_notes ?? '' });

    return (
        <Section title="Staff notes">
            <form
                className="grid gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(TenantController.update.url(tenant.id), {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    });
                }}
            >
                <label htmlFor="staff_notes" className="sr-only">
                    Staff notes
                </label>
                <textarea
                    id="staff_notes"
                    rows={5}
                    value={form.data.staff_notes}
                    onChange={(e) =>
                        form.setData('staff_notes', e.target.value)
                    }
                    className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                />
                <p className="text-xs text-muted-foreground">
                    Only YardRunner staff see these.
                </p>
                <div>
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={form.processing || !form.isDirty}
                    >
                        Save notes
                    </Button>
                </div>
            </form>
        </Section>
    );
}

export default function ShowTenant({
    tenant,
    stats,
    days,
    integrations,
    yards,
    users,
    audit,
}: Props) {
    const change =
        stats.prior_orders > 0
            ? (stats.orders - stats.prior_orders) / stats.prior_orders
            : null;

    return (
        <>
            <Head title={tenant.name} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <Link
                            href={TenantController.index()}
                            className="hover:underline"
                        >
                            Tenants
                        </Link>
                    }
                    title={
                        <span className="flex flex-wrap items-center gap-2.5">
                            {tenant.name}
                            <TenantStatusChip status={tenant.status} />
                            <HealthChip health={tenant.health} />
                        </span>
                    }
                    description={
                        <span className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            <span className="figure">{tenant.slug}</span>
                            <span>{tenant.plan_label ?? 'No plan set'}</span>
                            {tenant.created_at && (
                                <span>
                                    Customer since{' '}
                                    {formatDate(tenant.created_at)}
                                </span>
                            )}
                            {tenant.owner && (
                                <span>
                                    Owner: {tenant.owner.name} (
                                    {tenant.owner.email})
                                </span>
                            )}
                            <span>
                                Last active {formatAgo(tenant.last_active_at)}
                            </span>
                        </span>
                    }
                />

                <section
                    aria-label="Last 30 days"
                    className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3"
                >
                    <Figure
                        label="Orders, 30 days"
                        value={stats.orders.toLocaleString('en-CA')}
                        note={`${formatChange(change)} vs prior 30 days`}
                        noteTone={
                            change === null || change === 0
                                ? 'muted'
                                : change > 0
                                  ? 'up'
                                  : 'down'
                        }
                    />
                    <Figure
                        label="Goods sales"
                        value={formatWholeMoney(
                            stats.sales_cents,
                            tenant.currency,
                        )}
                        note="Before tax"
                    />
                    <Figure
                        label="Rentals booked"
                        value={stats.rentals}
                        note={`${stats.rentals_out} out now`}
                    />
                    <Figure label="Deliveries done" value={stats.deliveries} />
                    <Figure
                        label="Stock movements"
                        value={stats.stock_movements.toLocaleString('en-CA')}
                    />
                    <Figure
                        label="Users this week"
                        value={stats.weekly_users}
                        note={`of ${stats.users} who can sign in`}
                    />
                </section>

                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[999_1_560px] flex-col gap-5">
                        <Section title="Orders per day">
                            <BarChart
                                label="Orders per day for the last 30 days"
                                data={days}
                                everyNthLabel={7}
                                xLabel={(d) =>
                                    new Date(
                                        `${d.date}T12:00:00`,
                                    ).toLocaleDateString('en-CA', {
                                        month: 'short',
                                        day: 'numeric',
                                    })
                                }
                                height={160}
                                series={[
                                    {
                                        key: 'orders',
                                        label: 'Orders',
                                        className: 'bg-primary',
                                    },
                                ]}
                            />
                        </Section>

                        <Section title="Integrations" flush>
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] border-t border-border">
                                {integrations.map((i) => (
                                    <div
                                        key={i.name}
                                        className="flex flex-col gap-1.5 border-b border-secondary px-5 py-4"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-sm font-semibold">
                                                {i.name}
                                            </span>
                                            <Chip tone={i.tone}>{i.state}</Chip>
                                        </div>
                                        {i.lines.map((line, n) => (
                                            <span
                                                key={n}
                                                className="text-[13px] break-words text-muted-foreground"
                                            >
                                                {line}
                                            </span>
                                        ))}
                                    </div>
                                ))}
                            </div>
                        </Section>

                        <Section title="Yards" flush>
                            <Table minWidth={480}>
                                <thead>
                                    <tr>
                                        <Th>Yard</Th>
                                        <Th>Code</Th>
                                        <Th numeric>Orders, 30 days</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {yards.length === 0 && (
                                        <EmptyRow colSpan={3}>
                                            No yards.
                                        </EmptyRow>
                                    )}
                                    {yards.map((y) => (
                                        <tr key={y.id}>
                                            <Td>
                                                <span className="font-semibold">
                                                    {y.name}
                                                </span>
                                                {y.is_default && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {' '}
                                                        · main
                                                    </span>
                                                )}
                                            </Td>
                                            <Td className="figure">{y.code}</Td>
                                            <Td numeric>{y.orders}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Section>

                        <Section
                            title="Users"
                            description={`${stats.weekly_users} of ${users.length} signed in this week`}
                            flush
                        >
                            <Table minWidth={640}>
                                <thead>
                                    <tr>
                                        <Th>Name</Th>
                                        <Th>Role</Th>
                                        <Th>Two-factor</Th>
                                        <Th>Last seen</Th>
                                        <Th>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {users.length === 0 && (
                                        <EmptyRow colSpan={5}>
                                            No users.
                                        </EmptyRow>
                                    )}
                                    {users.map((u) => (
                                        <tr key={u.id}>
                                            <Td>
                                                <span className="font-semibold">
                                                    {u.name}
                                                </span>
                                                {!u.joined && (
                                                    <>
                                                        {' '}
                                                        <Chip tone="warning">
                                                            Invited
                                                        </Chip>
                                                    </>
                                                )}
                                                <div className="text-xs text-muted-foreground">
                                                    {u.email}
                                                </div>
                                            </Td>
                                            <Td>{u.role}</Td>
                                            <Td>
                                                <span
                                                    className={cn(
                                                        'text-xs font-semibold',
                                                        u.two_factor
                                                            ? 'text-success'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {u.two_factor
                                                        ? 'On'
                                                        : 'Off'}
                                                </span>
                                            </Td>
                                            <Td className="whitespace-nowrap text-muted-foreground">
                                                {formatAgo(u.last_seen_at)}
                                            </Td>
                                            <Td className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            TenantController.sendPasswordLink.url(
                                                                {
                                                                    tenant: tenant.id,
                                                                    user: u.id,
                                                                },
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Send password link
                                                </Button>
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Section>

                        <Section title="Audit log" flush>
                            {audit.length === 0 ? (
                                <p className="px-5 pb-5 text-sm text-muted-foreground">
                                    Nothing recorded yet.
                                </p>
                            ) : (
                                <ol className="divide-y divide-secondary border-t border-border">
                                    {audit.map((e) => (
                                        <li
                                            key={e.id}
                                            className="flex flex-wrap gap-x-4 gap-y-1 px-5 py-3 text-sm"
                                        >
                                            <span className="figure w-36 shrink-0 text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    e.at,
                                                    tenant.timezone,
                                                )}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div>
                                                    {e.subject} {e.event}
                                                    {e.note && (
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            · {e.note}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {e.who}
                                                </div>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Section>
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_320px] flex-col gap-5">
                        <Subscription tenant={tenant} />
                        <Branding tenant={tenant} />
                        <Notes tenant={tenant} />
                    </aside>
                </div>
            </PageBody>
        </>
    );
}
