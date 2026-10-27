import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import { BarChart } from '@/components/admin/parts';
import type { TenantStatus } from '@/components/admin/parts';
import { Chip } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { formatAgo, formatChange, formatWholeMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Week = {
    start: string;
    orders: number;
    sales_cents: number;
    rentals: number;
};

type Props = {
    weeks: Week[];
    modules: { key: string; label: string }[];
    usage: { id: number; name: string; cells: (number | null)[] }[];
    engagement: {
        id: number;
        name: string;
        status: TenantStatus;
        weekly_users: number;
        monthly_users: number;
        users: number;
        orders: number;
        change: number | null;
        last_active_at: string | null;
        risk: 'high' | 'medium' | 'low';
    }[];
    cohorts: { month: string; size: number; cells: (number | null)[] }[];
    filters: { plan: string | null; status: 'live' | 'active' | 'trial' };
    plans: { value: string; label: string }[];
};

type Metric = 'orders' | 'sales_cents' | 'rentals';

const metrics: { value: Metric; label: string; note: string }[] = [
    { value: 'orders', label: 'Orders', note: 'Sales orders confirmed' },
    {
        value: 'sales_cents',
        label: 'Goods sales',
        note: 'Goods sales before tax',
    },
    { value: 'rentals', label: 'Rentals', note: 'Rentals booked' },
];

const risk = {
    high: ['High', 'danger'],
    medium: ['Medium', 'warning'],
    low: ['Low', 'success'],
} as const;

function heat(value: number | null): string {
    if (value === null) {
        return 'border border-dashed border-border text-muted-foreground';
    }

    if (value < 10) {
        return 'bg-[#fff7ed] text-foreground';
    }

    if (value < 50) {
        return 'bg-[#fed7aa] text-foreground';
    }

    if (value < 200) {
        return 'bg-[#fb923c] text-foreground';
    }

    return 'bg-[#9a3412] text-white';
}

function retention(value: number | null): string {
    if (value === null) {
        return '';
    }

    if (value >= 95) {
        return 'bg-foreground text-background';
    }

    if (value >= 85) {
        return 'bg-[#44403c] text-white';
    }

    if (value >= 70) {
        return 'bg-[#a8a29e] text-foreground';
    }

    return 'bg-secondary text-foreground';
}

export default function Reports({
    weeks,
    modules,
    usage,
    engagement,
    cohorts,
    filters,
    plans,
}: Props) {
    const [metric, setMetric] = useState<Metric>('orders');
    const current = metrics.find((m) => m.value === metric) ?? metrics[0];

    const filter = (change: Partial<Props['filters']>) =>
        router.get(
            ReportController.url(),
            Object.fromEntries(
                Object.entries({ ...filters, ...change }).filter(
                    ([, v]) => v !== null && v !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Activity reports" />
            <PageBody>
                <PageHeader
                    eyebrow="Across tenants"
                    title="Activity reports"
                    actions={
                        <>
                            <NativeSelect
                                aria-label="Tenants"
                                className="h-9 w-auto"
                                value={filters.status}
                                onChange={(e) =>
                                    filter({
                                        status: e.target
                                            .value as Props['filters']['status'],
                                    })
                                }
                            >
                                <option value="live">Active and trial</option>
                                <option value="active">Paying only</option>
                                <option value="trial">Trials only</option>
                            </NativeSelect>
                            <NativeSelect
                                aria-label="Plan"
                                className="h-9 w-auto"
                                value={filters.plan ?? ''}
                                onChange={(e) =>
                                    filter({ plan: e.target.value || null })
                                }
                            >
                                <option value="">All plans</option>
                                {plans.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </>
                    }
                />

                <Section
                    title="Volume per week"
                    description={`${current.note}, last 12 weeks`}
                    actions={
                        <ToggleButtons
                            size="sm"
                            label="Measure"
                            options={metrics}
                            value={metric}
                            onChange={setMetric}
                        />
                    }
                >
                    <BarChart
                        label={`${current.note} per week for the last 12 weeks`}
                        data={weeks}
                        height={200}
                        showTotals
                        formatTotal={(n) =>
                            metric === 'sales_cents'
                                ? formatWholeMoney(n)
                                : n.toLocaleString('en-CA')
                        }
                        xLabel={(w) =>
                            new Date(`${w.start}T12:00:00`).toLocaleDateString(
                                'en-CA',
                                { month: 'short', day: 'numeric' },
                            )
                        }
                        series={[
                            {
                                key: metric,
                                label: current.label,
                                className: 'bg-foreground',
                            },
                        ]}
                    />
                </Section>

                <Section
                    flush
                    title="Module usage by tenant"
                    description="Actions per week over the last 4 weeks. A dashed cell means the module is off or not connected."
                >
                    <Table minWidth={860}>
                        <thead>
                            <tr>
                                <Th>Tenant</Th>
                                {modules.map((m) => (
                                    <Th key={m.key} className="text-center">
                                        {m.label}
                                    </Th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {usage.length === 0 && (
                                <EmptyRow colSpan={modules.length + 1}>
                                    No tenants match.
                                </EmptyRow>
                            )}
                            {usage.map((row) => (
                                <tr key={row.id}>
                                    <th
                                        scope="row"
                                        className="border-b border-secondary px-4 py-2 text-left font-semibold whitespace-nowrap"
                                    >
                                        <Link
                                            href={TenantController.show(row.id)}
                                            className="hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                    </th>
                                    {row.cells.map((v, i) => (
                                        <td
                                            key={i}
                                            className="border-b border-secondary p-1"
                                        >
                                            <div
                                                className={cn(
                                                    'figure flex h-8 items-center justify-center rounded-[3px] text-xs',
                                                    heat(v),
                                                )}
                                            >
                                                {v === null ? (
                                                    <>
                                                        <span aria-hidden="true">
                                                            —
                                                        </span>
                                                        <span className="sr-only">
                                                            Off
                                                        </span>
                                                    </>
                                                ) : (
                                                    v
                                                )}
                                            </div>
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Section>

                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        flush
                        title="Engagement"
                        description="Highest churn risk first"
                        className="min-w-0 flex-[999_1_560px]"
                    >
                        <Table minWidth={680}>
                            <thead>
                                <tr>
                                    <Th>Tenant</Th>
                                    <Th numeric>Users this week</Th>
                                    <Th numeric>Users this month</Th>
                                    <Th numeric>Orders, 30 days</Th>
                                    <Th>Last active</Th>
                                    <Th>Churn risk</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {engagement.length === 0 && (
                                    <EmptyRow colSpan={6}>
                                        No tenants match.
                                    </EmptyRow>
                                )}
                                {engagement.map((e) => (
                                    <tr key={e.id}>
                                        <Td>
                                            <Link
                                                href={TenantController.show(
                                                    e.id,
                                                )}
                                                className="font-semibold hover:underline"
                                            >
                                                {e.name}
                                            </Link>
                                        </Td>
                                        <Td numeric>
                                            {e.weekly_users} / {e.users}
                                        </Td>
                                        <Td numeric>{e.monthly_users}</Td>
                                        <Td numeric>
                                            {e.orders.toLocaleString('en-CA')}{' '}
                                            <span
                                                className={cn(
                                                    'text-xs',
                                                    e.change === null
                                                        ? 'text-muted-foreground'
                                                        : e.change >= 0
                                                          ? 'text-success'
                                                          : 'text-danger',
                                                )}
                                            >
                                                {formatChange(e.change)}
                                            </span>
                                        </Td>
                                        <Td className="whitespace-nowrap text-muted-foreground">
                                            {formatAgo(e.last_active_at)}
                                        </Td>
                                        <Td>
                                            <Chip tone={risk[e.risk][1]}>
                                                {risk[e.risk][0]}
                                            </Chip>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Section>

                    <Section
                        title="Retention by signup month"
                        description="Share of tenants still using Yardline N months after signing up"
                        className="min-w-0 flex-[1_1_380px]"
                    >
                        <div className="overflow-x-auto">
                            <table className="figure w-full min-w-[340px] border-separate border-spacing-[3px] text-xs">
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th
                                            scope="col"
                                            className="p-1 text-left font-medium"
                                        >
                                            Cohort
                                        </th>
                                        <th scope="col" className="font-medium">
                                            Tenants
                                        </th>
                                        {[1, 2, 3, 4, 5].map((m) => (
                                            <th
                                                key={m}
                                                scope="col"
                                                className="font-medium"
                                            >
                                                M{m}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {cohorts.map((c) => (
                                        <tr key={c.month}>
                                            <th
                                                scope="row"
                                                className="p-1 text-left font-medium whitespace-nowrap"
                                            >
                                                {c.month}
                                            </th>
                                            <td className="text-center text-muted-foreground">
                                                {c.size}
                                            </td>
                                            {c.cells.map((v, i) => (
                                                <td
                                                    key={i}
                                                    className={cn(
                                                        'rounded-[3px] px-1 py-2 text-center',
                                                        retention(v),
                                                    )}
                                                >
                                                    {v === null ? '' : `${v}%`}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Section>
                </div>
            </PageBody>
        </>
    );
}
