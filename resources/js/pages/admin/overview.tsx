import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import IntegrationHealthController from '@/actions/App/Http/Controllers/Admin/IntegrationHealthController';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import {
    BarChart,
    Figure,
    HealthChip,
    PercentBar,
    Spark,
} from '@/components/admin/parts';
import type { Health } from '@/components/admin/parts';
import { Button } from '@/components/ui/button';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { formatAgo, formatChange, formatWholeMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    kpis: {
        active_tenants: number;
        new_tenants: number;
        trials: number;
        trials_ending: number;
        mrr_cents: number;
        sales_cents: number;
        prior_sales_cents: number;
        orders: number;
        prior_orders: number;
        shopify_share: number | null;
        rentals_out: number;
    };
    busiest: {
        id: number;
        name: string;
        yards: number;
        orders: number;
        spark: number[];
        rentals: number;
        weekly_users: number;
        users: number;
        last_active_at: string | null;
        health: Health;
    }[];
    attention: {
        tone: 'danger' | 'warning';
        title: string;
        detail: string;
        tenant: { id: number; name: string } | null;
        link: 'tenant' | 'integrations';
    }[];
    growth: { month: string; new: number; churned: number }[];
    adoption: { key: string; label: string; percent: number }[];
};

function change(now: number, before: number): number | null {
    return before > 0 ? (now - before) / before : null;
}

function tone(c: number | null): 'up' | 'down' | 'muted' {
    return c === null || c === 0 ? 'muted' : c > 0 ? 'up' : 'down';
}

export default function AdminOverview({
    kpis,
    busiest,
    attention,
    growth,
    adoption,
}: Props) {
    const salesChange = change(kpis.sales_cents, kpis.prior_sales_cents);
    const ordersChange = change(kpis.orders, kpis.prior_orders);
    const today = new Date().toLocaleDateString('en-CA', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });

    return (
        <>
            <Head title="Back office" />
            <PageBody>
                <PageHeader
                    eyebrow={today}
                    title="Overview"
                    actions={
                        <Button asChild>
                            <Link href={TenantController.create()}>
                                <Plus />
                                New tenant
                            </Link>
                        </Button>
                    }
                />

                <section
                    aria-label="Key figures"
                    className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3"
                >
                    <Figure
                        label="Active tenants"
                        value={kpis.active_tenants}
                        note={`${kpis.new_tenants} new this month`}
                    />
                    <Figure
                        label="In trial"
                        value={kpis.trials}
                        note={
                            kpis.trials_ending > 0
                                ? `${kpis.trials_ending} ending within a week`
                                : 'None ending this week'
                        }
                    />
                    <Figure
                        label="MRR"
                        value={formatWholeMoney(kpis.mrr_cents)}
                        note="From plans recorded here"
                    />
                    <Figure
                        label="Goods sales, 30 days"
                        value={formatWholeMoney(kpis.sales_cents)}
                        note={`${formatChange(salesChange)} vs prior 30 days`}
                        noteTone={tone(salesChange)}
                    />
                    <Figure
                        label="Orders, 30 days"
                        value={kpis.orders.toLocaleString('en-CA')}
                        note={
                            kpis.shopify_share === null
                                ? `${formatChange(ordersChange)} vs prior 30 days`
                                : `${Math.round(kpis.shopify_share * 100)}% from Shopify`
                        }
                        noteTone={
                            kpis.shopify_share === null
                                ? tone(ordersChange)
                                : 'muted'
                        }
                    />
                    <Figure
                        label="Rentals out now"
                        value={kpis.rentals_out}
                        note="Across every tenant"
                    />
                </section>

                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        flush
                        title="Busiest tenants"
                        description="Most orders in the last 30 days"
                        className="min-w-0 flex-[999_1_560px]"
                        actions={
                            <Link
                                href={TenantController.index()}
                                className="text-sm font-medium text-primary"
                            >
                                All tenants →
                            </Link>
                        }
                    >
                        <Table minWidth={640}>
                            <thead>
                                <tr>
                                    <Th>Tenant</Th>
                                    <Th>Orders, 30 days</Th>
                                    <Th numeric>Rentals</Th>
                                    <Th numeric>Users this week</Th>
                                    <Th>Health</Th>
                                    <Th>Last active</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {busiest.length === 0 && (
                                    <EmptyRow colSpan={6}>
                                        No tenants yet.
                                    </EmptyRow>
                                )}
                                {busiest.map((t) => (
                                    <tr
                                        key={t.id}
                                        className="hover:bg-accent/50"
                                    >
                                        <Td>
                                            <Link
                                                href={TenantController.show(
                                                    t.id,
                                                )}
                                                className="font-semibold hover:underline"
                                            >
                                                {t.name}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {t.yards}{' '}
                                                {t.yards === 1
                                                    ? 'yard'
                                                    : 'yards'}
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="flex items-center gap-3">
                                                <Spark values={t.spark} />
                                                <span className="figure">
                                                    {t.orders.toLocaleString(
                                                        'en-CA',
                                                    )}
                                                </span>
                                            </div>
                                        </Td>
                                        <Td numeric>{t.rentals}</Td>
                                        <Td numeric>
                                            {t.weekly_users} of {t.users}
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
                    </Section>

                    <Section
                        flush
                        title="Needs attention"
                        className="min-w-0 flex-[1_1_340px]"
                    >
                        {attention.length === 0 ? (
                            <p className="px-5 pb-5 text-sm text-muted-foreground">
                                Nothing needs attention right now.
                            </p>
                        ) : (
                            <ul className="divide-y divide-secondary border-t border-border">
                                {attention.map((a, i) => (
                                    <li
                                        key={i}
                                        className="flex gap-3 px-5 py-3.5"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'mt-1.5 size-2.5 shrink-0 rounded-full',
                                                a.tone === 'danger'
                                                    ? 'bg-danger'
                                                    : 'bg-warning',
                                            )}
                                        />
                                        <div className="min-w-0 text-sm">
                                            <div className="font-semibold">
                                                {a.title}
                                            </div>
                                            <div className="text-muted-foreground">
                                                {a.tenant && (
                                                    <>{a.tenant.name} · </>
                                                )}
                                                {a.detail}
                                            </div>
                                            <Link
                                                href={
                                                    a.link === 'tenant' &&
                                                    a.tenant
                                                        ? TenantController.show(
                                                              a.tenant.id,
                                                          )
                                                        : IntegrationHealthController.index()
                                                }
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {a.link === 'tenant'
                                                    ? 'Open tenant'
                                                    : 'Integration health'}
                                            </Link>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                </div>

                <div className="flex flex-wrap items-stretch gap-5">
                    <Section
                        title="Tenant growth"
                        description="New tenants and cancellations per month"
                        className="min-w-0 flex-[1_1_480px]"
                    >
                        <BarChart
                            label="New and cancelled tenants per month for the last 12 months"
                            data={growth}
                            xLabel={(g) => g.month}
                            series={[
                                {
                                    key: 'new',
                                    label: 'New',
                                    className: 'bg-primary',
                                },
                                {
                                    key: 'churned',
                                    label: 'Cancelled',
                                    className: 'bg-muted-foreground',
                                },
                            ]}
                        />
                    </Section>

                    <Section
                        title="Module adoption"
                        description="Share of live tenants that used it in the last 7 days"
                        className="min-w-0 flex-[1_1_380px]"
                    >
                        <div className="flex flex-col gap-3.5">
                            {adoption.map((m) => (
                                <PercentBar
                                    key={m.key}
                                    label={m.label}
                                    percent={m.percent}
                                />
                            ))}
                        </div>
                    </Section>
                </div>
            </PageBody>
        </>
    );
}
