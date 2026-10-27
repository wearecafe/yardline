import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import {
    ConnectionBadges,
    HealthChip,
    TenantStatusChip,
} from '@/components/admin/parts';
import type { Health, TenantStatus } from '@/components/admin/parts';
import { Button } from '@/components/ui/button';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { SearchBox } from '@/components/yard/search-box';
import { formatAgo, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    slug: string;
    status: TenantStatus;
    plan: string | null;
    mrr_cents: number | null;
    yards: number;
    users: number;
    orders: number;
    rentals: number;
    last_active_at: string | null;
    connections: { code: string; label: string; ok: boolean }[];
    health: Health;
};

type Filters = {
    status: TenantStatus | null;
    search: string | null;
    plan: string | null;
    connection: string | null;
};

type Props = {
    tenants: Paginated<Row>;
    counts: Record<'all' | TenantStatus, number>;
    filters: Filters;
    plans: { value: string; label: string }[];
};

const tabs: {
    value: TenantStatus | null;
    label: string;
    key: 'all' | TenantStatus;
}[] = [
    { value: null, label: 'All', key: 'all' },
    { value: 'active', label: 'Active', key: 'active' },
    { value: 'trial', label: 'Trial', key: 'trial' },
    { value: 'past_due', label: 'Past due', key: 'past_due' },
    { value: 'suspended', label: 'Suspended', key: 'suspended' },
    { value: 'cancelled', label: 'Cancelled', key: 'cancelled' },
];

function query(filters: Filters, change: Partial<Filters>) {
    const next = { ...filters, ...change };

    return Object.fromEntries(
        Object.entries(next).filter(([, v]) => v !== null && v !== ''),
    ) as Record<string, string>;
}

export default function Tenants({ tenants, counts, filters, plans }: Props) {
    const visit = (change: Partial<Filters>) =>
        router.get(TenantController.index.url(), query(filters, change), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    return (
        <>
            <Head title="Tenants" />
            <PageBody>
                <PageHeader
                    title="Tenants"
                    description="Every business using YardRunner."
                    actions={
                        <Button asChild>
                            <Link href={TenantController.create()}>
                                <Plus />
                                New tenant
                            </Link>
                        </Button>
                    }
                />

                <nav
                    aria-label="Filter by status"
                    className="flex flex-wrap gap-1 border-b border-border"
                >
                    {tabs.map((tab) => {
                        const active = filters.status === tab.value;

                        return (
                            <Link
                                key={tab.key}
                                href={TenantController.index.url({
                                    query: query(filters, {
                                        status: tab.value,
                                    }),
                                })}
                                preserveState
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px inline-flex h-11 items-center gap-1.5 border-b-2 px-3.5 text-sm',
                                    active
                                        ? 'border-primary font-semibold'
                                        : 'border-transparent hover:border-input',
                                )}
                            >
                                {tab.label}
                                <span className="figure text-xs text-muted-foreground">
                                    {counts[tab.key]}
                                </span>
                            </Link>
                        );
                    })}
                </nav>

                <div className="flex flex-wrap items-center gap-3">
                    <SearchBox
                        value={filters.search ?? undefined}
                        placeholder="Name or owner email"
                        extra={query(filters, { search: null })}
                    />
                    <NativeSelect
                        aria-label="Plan"
                        className="h-9 w-auto"
                        value={filters.plan ?? ''}
                        onChange={(e) =>
                            visit({ plan: e.target.value || null })
                        }
                    >
                        <option value="">All plans</option>
                        {plans.map((p) => (
                            <option key={p.value} value={p.value}>
                                {p.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Connections"
                        className="h-9 w-auto"
                        value={filters.connection ?? ''}
                        onChange={(e) =>
                            visit({ connection: e.target.value || null })
                        }
                    >
                        <option value="">Any connections</option>
                        <option value="quickbooks">QuickBooks connected</option>
                        <option value="errors">Has sync errors</option>
                    </NativeSelect>
                </div>

                <div>
                    <Table minWidth={1040}>
                        <thead>
                            <tr>
                                <Th>Tenant</Th>
                                <Th>Status</Th>
                                <Th>Plan</Th>
                                <Th>Connected</Th>
                                <Th numeric>Yards</Th>
                                <Th numeric>Users</Th>
                                <Th numeric>Orders 30d</Th>
                                <Th numeric>Rentals 30d</Th>
                                <Th numeric>MRR</Th>
                                <Th>Health</Th>
                                <Th>Last active</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.data.length === 0 && (
                                <EmptyRow colSpan={11}>
                                    No tenants match.
                                </EmptyRow>
                            )}
                            {tenants.data.map((t) => (
                                <tr key={t.id} className="hover:bg-accent/50">
                                    <Td>
                                        <Link
                                            href={TenantController.show(t.id)}
                                            className="font-semibold hover:underline"
                                        >
                                            {t.name}
                                        </Link>
                                        <div className="figure text-xs text-muted-foreground">
                                            {t.slug}
                                        </div>
                                    </Td>
                                    <Td>
                                        <TenantStatusChip status={t.status} />
                                    </Td>
                                    <Td className="whitespace-nowrap">
                                        {t.plan ?? (
                                            <span className="text-muted-foreground">
                                                Not set
                                            </span>
                                        )}
                                    </Td>
                                    <Td>
                                        <ConnectionBadges
                                            connections={t.connections}
                                        />
                                    </Td>
                                    <Td numeric>{t.yards}</Td>
                                    <Td numeric>{t.users}</Td>
                                    <Td numeric>
                                        {t.orders.toLocaleString('en-CA')}
                                    </Td>
                                    <Td numeric>{t.rentals}</Td>
                                    <Td numeric>
                                        {t.mrr_cents === null
                                            ? '—'
                                            : formatMoney(t.mrr_cents)}
                                    </Td>
                                    <Td>
                                        <HealthChip health={t.health} />
                                    </Td>
                                    <Td className="whitespace-nowrap text-muted-foreground">
                                        {formatAgo(t.last_active_at)}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={tenants} />
                </div>
            </PageBody>
        </>
    );
}
