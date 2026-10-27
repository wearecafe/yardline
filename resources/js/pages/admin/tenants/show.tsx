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
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import {
    centsToInput,
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
        currency: string;
        timezone: string;
        created_at: string | null;
        last_active_at: string | null;
        status_changed_at: string | null;
        staff_notes: string | null;
        modules: { key: string; label: string; on: boolean }[];
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
    options: {
        statuses: { value: TenantStatus; label: string }[];
        plans: { value: string; label: string }[];
    };
};

function AccountForm({ tenant, options }: Pick<Props, 'tenant' | 'options'>) {
    const form = useForm({
        status: tenant.status,
        plan: tenant.plan ?? '',
        monthly_price: centsToInput(tenant.monthly_price_cents),
        trial_ends_at: tenant.trial_ends_at ?? '',
    });

    return (
        <Section
            title="Plan and status"
            description="Recorded here for reporting and MRR. Nothing is charged, and the status doesn’t block sign-in yet."
        >
            <form
                className="grid gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        plan: data.plan || null,
                        monthly_price: data.monthly_price || null,
                        trial_ends_at: data.trial_ends_at || null,
                    }));
                    form.patch(TenantController.update.url(tenant.id), {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    });
                }}
            >
                <Field
                    label="Status"
                    htmlFor="status"
                    error={form.errors.status}
                >
                    <NativeSelect
                        id="status"
                        value={form.data.status}
                        onChange={(e) =>
                            form.setData(
                                'status',
                                e.target.value as TenantStatus,
                            )
                        }
                    >
                        {options.statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                {form.data.status === 'trial' && (
                    <Field
                        label="Trial ends"
                        htmlFor="trial_ends_at"
                        error={form.errors.trial_ends_at}
                    >
                        <Input
                            id="trial_ends_at"
                            type="date"
                            value={form.data.trial_ends_at}
                            onChange={(e) =>
                                form.setData('trial_ends_at', e.target.value)
                            }
                        />
                    </Field>
                )}
                <Field label="Plan" htmlFor="plan" error={form.errors.plan}>
                    <NativeSelect
                        id="plan"
                        value={form.data.plan}
                        onChange={(e) => form.setData('plan', e.target.value)}
                    >
                        <option value="">Not set</option>
                        {options.plans.map((p) => (
                            <option key={p.value} value={p.value}>
                                {p.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label={`Monthly price (${tenant.currency})`}
                    htmlFor="monthly_price"
                    error={form.errors.monthly_price}
                >
                    <Input
                        id="monthly_price"
                        inputMode="decimal"
                        value={form.data.monthly_price}
                        onChange={(e) =>
                            form.setData('monthly_price', e.target.value)
                        }
                    />
                </Field>
                <div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing || !form.isDirty}
                    >
                        Save plan and status
                    </Button>
                </div>
            </form>
        </Section>
    );
}

function Modules({ tenant }: Pick<Props, 'tenant'>) {
    const toggle = (key: string, on: boolean) =>
        router.patch(
            TenantController.update.url(tenant.id),
            { modules: { [key]: on } },
            { preserveScroll: true },
        );

    return (
        <Section
            title="Modules"
            description="Recorded for now: switching one off doesn’t hide it from the tenant yet."
        >
            <ul className="-my-1 divide-y divide-secondary">
                {tenant.modules.map((m) => (
                    <li
                        key={m.key}
                        className="flex min-h-12 items-center justify-between gap-3"
                    >
                        <span className="text-sm">{m.label}</span>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={m.on}
                            aria-label={m.label}
                            onClick={() => toggle(m.key, !m.on)}
                            className={cn(
                                'flex h-7 w-12 shrink-0 rounded-full p-0.5 transition-colors',
                                m.on
                                    ? 'justify-end bg-primary'
                                    : 'justify-start bg-[#a8a29e]',
                            )}
                        >
                            <span className="size-6 rounded-full bg-white shadow-sm" />
                        </button>
                    </li>
                ))}
            </ul>
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
    options,
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
                        <AccountForm tenant={tenant} options={options} />
                        <Modules tenant={tenant} />
                        <Notes tenant={tenant} />
                    </aside>
                </div>
            </PageBody>
        </>
    );
}
