import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import ProfitReportController from '@/actions/App/Http/Controllers/ProfitReportController';
import FleetController from '@/actions/App/Http/Controllers/Rentals/FleetController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import {
    Notice,
    PageBody,
    PageHeader,
    Section,
} from '@/components/yard/page-header';
import { Tabs } from '@/components/yard/tabs';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';
import { getJson } from '@/lib/json';
import { cn } from '@/lib/utils';

type Option = { value: string; label: string };

type LineKey =
    | 'goods_sales'
    | 'delivery_fees'
    | 'pallet_fees'
    | 'restocking_fees'
    | 'returns'
    | 'rental_charges'
    | 'damage_waiver'
    | 'rental_delivery'
    | 'rental_extras'
    | 'cogs'
    | 'returned_to_stock'
    | 'adjustments'
    | 'maintenance'
    | 'card_fees';

type Group = 'goods_revenue' | 'rental_revenue' | 'costs';

type Segment = {
    revenue: number;
    costs: number;
    profit: number;
    margin: number | null;
};

type Summary = {
    lines: Record<LineKey, number>;
    goods: Segment;
    rentals: Segment;
    net_revenue: number;
    direct_costs: number;
    gross_profit: number;
    margin: number | null;
};

type ProfitRow = {
    sales: number;
    cost: number;
    profit: number;
    margin: number | null;
};

type Filters = {
    period: string;
    compare: string;
    business: string;
    from: string;
    to: string;
    label: string;
    location_id: number | null;
    channel: string | null;
};

type Props = {
    filters: Filters;
    options: {
        periods: Option[];
        compare: Option[];
        business: Option[];
        channels: Option[];
        locations: { id: number; name: string }[];
    };
    lines: { key: LineKey; label: string; group: Group }[];
    summary: Summary;
    comparison: { label: string; summary: Summary } | null;
    trend: {
        month: string;
        label: string;
        net_revenue: number;
        gross_profit: number;
        margin: number | null;
    }[];
    memo: {
        discounts: number;
        rule_discounts: number;
        manual_discounts: number;
        exempt_discounts: number;
        deposits_held: number;
        missing_lines: number;
        missing_revenue: number;
    };
    categories: (ProfitRow & { category: string; missing: number })[];
    products: (ProfitRow & {
        product_id: number | null;
        sku: string | null;
        name: string;
        category: string | null;
        quantity: number;
        unit: string | null;
        missing: number;
    })[];
    customers: (ProfitRow & {
        organization_id: number | null;
        name: string;
        price_list: string | null;
        orders: number;
        discounts: number;
    })[];
    units: {
        unit_id: number;
        serial: string;
        name: string;
        rentals: number;
        revenue: number;
        maintenance: number;
        profit: number;
    }[];
    includes: { goods: boolean; rentals: boolean };
};

type DetailRow = {
    date: string;
    reference: string;
    who: string | null;
    description: string;
    amount: number;
    href: string | null;
};

const groups: { key: Group; heading: string; subtotal: string }[] = [
    {
        key: 'goods_revenue',
        heading: 'Revenue: goods',
        subtotal: 'Goods revenue',
    },
    {
        key: 'rental_revenue',
        heading: 'Revenue: rentals',
        subtotal: 'Rental revenue',
    },
    { key: 'costs', heading: 'Direct costs', subtotal: 'Total direct costs' },
];

const lineNotes: Partial<Record<LineKey, string>> = {
    delivery_fees: 'driver time and fuel are in QuickBooks',
    adjustments: 'shrinkage, damage and counts',
};

function percent(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)}%`;
}

function share(amount: number, revenue: number): string {
    return revenue === 0 ? '—' : `${((amount / revenue) * 100).toFixed(1)}%`;
}

/** "(1,234.00)" for negatives, as on a statement. */
function amount(cents: number): string {
    return cents < 0 ? `(${formatMoney(-cents)})` : formatMoney(cents);
}

function subtotal(summary: Summary, group: Group): number {
    if (group === 'goods_revenue') {
        return summary.goods.revenue;
    }

    if (group === 'rental_revenue') {
        return summary.rentals.revenue;
    }

    return summary.direct_costs;
}

/**
 * Profit and loss for the owner: revenue from goods and rentals, less the
 * direct costs of earning it, down to gross profit. Net profit is in
 * QuickBooks.
 */
export default function ProfitReport(props: Props) {
    const {
        filters,
        summary,
        comparison,
        memo,
        includes,
        categories,
        products,
        customers,
        units,
        trend,
    } = props;
    const [detail, setDetail] = useState<{
        key: LineKey | 'missing';
        label: string;
    } | null>(null);
    const query = useMemo(() => queryFor(filters), [filters]);
    const marginDelta =
        summary.margin !== null &&
        comparison !== null &&
        comparison.summary.margin !== null
            ? summary.margin - comparison.summary.margin
            : null;
    const open = (key: LineKey | 'missing', label: string) =>
        setDetail({ key, label });

    const tabs = [
        {
            value: 'summary' as const,
            label: 'Summary',
            content: <SummaryTab {...props} onOpen={open} />,
        },
        {
            value: 'trend' as const,
            label: 'Trend',
            content: <TrendTab trend={trend} />,
        },
        ...(includes.goods
            ? [
                  {
                      value: 'categories' as const,
                      label: 'By category',
                      content: <CategoriesTable rows={categories} />,
                  },
                  {
                      value: 'products' as const,
                      label: 'By product',
                      content: <ProductsTable rows={products} />,
                  },
                  {
                      value: 'customers' as const,
                      label: 'By customer',
                      content: <CustomersTable rows={customers} />,
                  },
              ]
            : []),
        ...(includes.rentals
            ? [
                  {
                      value: 'units' as const,
                      label: 'By rental unit',
                      content: <UnitsTable rows={units} />,
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Profit and loss" />
            <PageBody>
                <PageHeader
                    eyebrow="Reports"
                    title="Profit and loss"
                    description="Gross profit from goods and rentals. Operating expenses and net profit are in QuickBooks."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    window.location.href = exportUrl(query);
                                }}
                            >
                                Export CSV
                            </Button>
                            <Button asChild>
                                <a
                                    href={ProfitReportController.pdf.url({
                                        query,
                                    })}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Download PDF
                                </a>
                            </Button>
                        </>
                    }
                />

                <FilterBar {...props} />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-4">
                    <Kpi
                        label="Net revenue"
                        value={formatMoney(summary.net_revenue)}
                        delta={change(
                            summary.net_revenue,
                            comparison?.summary.net_revenue,
                        )}
                        compared={
                            comparison &&
                            `${formatMoney(comparison.summary.net_revenue)} in ${comparison.label}`
                        }
                    />
                    <Kpi
                        label="Gross profit"
                        value={formatMoney(summary.gross_profit)}
                        delta={change(
                            summary.gross_profit,
                            comparison?.summary.gross_profit,
                        )}
                        compared={
                            comparison &&
                            `${formatMoney(comparison.summary.gross_profit)} in ${comparison.label}`
                        }
                    />
                    <Kpi
                        label="Gross margin"
                        value={percent(summary.margin)}
                        delta={
                            marginDelta !== null
                                ? {
                                      value: marginDelta,
                                      text: `${Math.abs(marginDelta).toFixed(1)} pt`,
                                  }
                                : null
                        }
                        compared={
                            comparison &&
                            `${percent(comparison.summary.margin)} in ${comparison.label}`
                        }
                    />
                    <div className="rounded-[10px] border border-border bg-card p-4">
                        <div className="text-muted-foreground">
                            Margin by business
                        </div>
                        <div className="mt-1.5 flex gap-6">
                            {includes.goods && (
                                <div>
                                    <div className="figure text-[22px] leading-tight">
                                        {percent(summary.goods.margin)}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Goods
                                    </div>
                                </div>
                            )}
                            {includes.rentals && (
                                <div>
                                    <div className="figure text-[22px] leading-tight">
                                        {percent(summary.rentals.margin)}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Rentals
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {memo.missing_lines > 0 && (
                    <Notice
                        tone="warning"
                        className="flex flex-wrap items-center justify-between gap-3"
                    >
                        <span className="flex items-center gap-2">
                            <TriangleAlert className="size-4 shrink-0" />
                            <span>
                                <strong>
                                    {memo.missing_lines}{' '}
                                    {memo.missing_lines === 1
                                        ? 'invoice line has'
                                        : 'invoice lines have'}{' '}
                                    no cost
                                </strong>{' '}
                                ({formatMoney(memo.missing_revenue)} of sales).
                                Gross profit is overstated until those products
                                have a cost.
                            </span>
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                open('missing', 'Invoice lines with no cost')
                            }
                        >
                            Review lines
                        </Button>
                    </Notice>
                )}

                <Tabs items={tabs} label="Report views" param="view" />

                <p className="text-xs text-muted-foreground">
                    {filters.label} · accrual, by invoice date (rentals when
                    they went out) · CAD, before tax. Deposits aren’t revenue.
                </p>
            </PageBody>

            {detail && (
                <DetailDialog
                    line={detail.key}
                    label={detail.label}
                    period={filters.label}
                    query={query}
                    onClose={() => setDetail(null)}
                />
            )}
        </>
    );
}

// ---------------------------------------------------------------- filters

function queryFor(filters: Filters): Record<string, string | number> {
    const query: Record<string, string | number> = {
        period: filters.period,
        compare: filters.compare,
        business: filters.business,
    };

    if (filters.period === 'custom') {
        query.from = filters.from;
        query.to = filters.to;
    }

    if (filters.location_id !== null) {
        query.location_id = filters.location_id;
    }

    if (filters.channel) {
        query.channel = filters.channel;
    }

    return query;
}

/** The CSV of whichever view is open. */
function exportUrl(query: Record<string, string | number>): string {
    const view =
        typeof window !== 'undefined'
            ? (new URL(window.location.href).searchParams.get('view') ??
              'summary')
            : 'summary';

    return ProfitReportController.csv.url({ query: { ...query, view } });
}

function FilterBar({ filters, options }: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const visit = (changes: Record<string, string | number | null>) => {
        const next: Record<string, string | number> = { ...queryFor(filters) };

        for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === '') {
                delete next[key];
            } else {
                next[key] = value;
            }
        }

        if (next.period !== 'custom') {
            delete next.from;
            delete next.to;
        }

        const view = new URL(window.location.href).searchParams.get('view');

        router.get(
            ProfitReportController.index.url({
                query: view ? { ...next, view } : next,
            }),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <div
            role="group"
            aria-label="Filters"
            className="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(170px,1fr))]"
        >
            <Field label="Period" htmlFor="pl-period">
                <NativeSelect
                    id="pl-period"
                    value={filters.period}
                    onChange={(e) =>
                        visit(
                            e.target.value === 'custom'
                                ? { period: 'custom', from, to }
                                : { period: e.target.value },
                        )
                    }
                >
                    {options.periods.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            {filters.period === 'custom' && (
                <>
                    <Field label="From" htmlFor="pl-from">
                        <Input
                            id="pl-from"
                            type="date"
                            value={from}
                            max={to}
                            onChange={(e) => setFrom(e.target.value)}
                            onBlur={() =>
                                from !== filters.from && from && visit({ from })
                            }
                        />
                    </Field>
                    <Field label="To" htmlFor="pl-to">
                        <Input
                            id="pl-to"
                            type="date"
                            value={to}
                            min={from}
                            onChange={(e) => setTo(e.target.value)}
                            onBlur={() =>
                                to !== filters.to && to && visit({ to })
                            }
                        />
                    </Field>
                </>
            )}
            <Field label="Compare to" htmlFor="pl-compare">
                <NativeSelect
                    id="pl-compare"
                    value={filters.compare}
                    onChange={(e) => visit({ compare: e.target.value })}
                >
                    {options.compare.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field label="Yard" htmlFor="pl-yard">
                <NativeSelect
                    id="pl-yard"
                    value={filters.location_id ?? ''}
                    onChange={(e) => visit({ location_id: e.target.value })}
                >
                    <option value="">All yards</option>
                    {options.locations.map((l) => (
                        <option key={l.id} value={l.id}>
                            {l.name}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field label="Business" htmlFor="pl-business">
                <NativeSelect
                    id="pl-business"
                    value={filters.business}
                    onChange={(e) => visit({ business: e.target.value })}
                >
                    {options.business.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field
                label="Sales channel"
                htmlFor="pl-channel"
                hint={filters.channel ? 'Goods only' : undefined}
            >
                <NativeSelect
                    id="pl-channel"
                    value={filters.channel ?? ''}
                    onChange={(e) => visit({ channel: e.target.value })}
                >
                    <option value="">All channels</option>
                    {options.channels.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
        </div>
    );
}

// ------------------------------------------------------------------- KPIs

type Change = { value: number; text: string } | null;

function change(current: number, previous: number | undefined): Change {
    if (previous === undefined || previous === 0) {
        return null;
    }

    const pct = ((current - previous) / Math.abs(previous)) * 100;

    return { value: pct, text: `${Math.abs(pct).toFixed(1)}%` };
}

function Kpi({
    label,
    value,
    delta,
    compared,
}: {
    label: string;
    value: ReactNode;
    delta: Change;
    compared: string | null | undefined;
}) {
    return (
        <div className="rounded-[10px] border border-border bg-card p-4">
            <div className="text-muted-foreground">{label}</div>
            <div className="figure mt-1.5 text-[28px] leading-tight">
                {value}
            </div>
            {compared && (
                <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-sm">
                    {delta && delta.value !== 0 && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 font-medium',
                                delta.value > 0
                                    ? 'text-success'
                                    : 'text-danger',
                            )}
                        >
                            {delta.value > 0 ? (
                                <ArrowUp className="size-3.5" aria-hidden />
                            ) : (
                                <ArrowDown className="size-3.5" aria-hidden />
                            )}
                            <span className="sr-only">
                                {delta.value > 0 ? 'Up' : 'Down'}
                            </span>
                            {delta.text}
                        </span>
                    )}
                    <span className="text-muted-foreground">vs {compared}</span>
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------- summary

function SummaryTab({
    lines,
    summary,
    comparison,
    memo,
    trend,
    includes,
    filters,
    onOpen,
}: Props & { onOpen: (key: LineKey, label: string) => void }) {
    const shown = groups.filter(
        (g) =>
            (g.key !== 'goods_revenue' || includes.goods) &&
            (g.key !== 'rental_revenue' || includes.rentals),
    );
    const cols = comparison ? 4 : 3;

    return (
        <div className="flex flex-wrap items-start gap-5">
            <Section
                title={`Statement · ${filters.label}`}
                description="Select an amount to see what’s behind it."
                className="min-w-0 flex-[3_1_520px]"
                flush
            >
                <Table minWidth={comparison ? 560 : 440}>
                    <thead>
                        <tr>
                            <Th>Line</Th>
                            <Th numeric>Amount</Th>
                            <Th numeric>% of revenue</Th>
                            {comparison && <Th numeric>{comparison.label}</Th>}
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((group) => (
                            <GroupRows
                                key={group.key}
                                group={group}
                                lines={lines.filter(
                                    (l) => l.group === group.key,
                                )}
                                summary={summary}
                                comparison={comparison?.summary ?? null}
                                cols={cols}
                                onOpen={onOpen}
                                before={
                                    group.key === 'costs' ? (
                                        <tr className="bg-secondary/60 font-semibold">
                                            <Td>Net revenue</Td>
                                            <Td numeric>
                                                {formatMoney(
                                                    summary.net_revenue,
                                                )}
                                            </Td>
                                            <Td numeric>100.0%</Td>
                                            {comparison && (
                                                <Td numeric>
                                                    {formatMoney(
                                                        comparison.summary
                                                            .net_revenue,
                                                    )}
                                                </Td>
                                            )}
                                        </tr>
                                    ) : null
                                }
                            />
                        ))}
                        <tr className="bg-accent text-base font-bold">
                            <Td>Gross profit</Td>
                            <Td numeric>{formatMoney(summary.gross_profit)}</Td>
                            <Td numeric>{percent(summary.margin)}</Td>
                            {comparison && (
                                <Td numeric>
                                    {formatMoney(
                                        comparison.summary.gross_profit,
                                    )}
                                </Td>
                            )}
                        </tr>
                    </tbody>
                </Table>
            </Section>

            <div className="flex min-w-0 flex-[2_1_320px] flex-col gap-5">
                <Section
                    title="Gross profit, last 6 months"
                    description="Bars are gross profit; labels are gross margin."
                >
                    <TrendBars trend={trend} />
                </Section>

                {includes.goods && includes.rentals && (
                    <Section title="Goods vs rentals" flush>
                        <Table>
                            <thead>
                                <tr>
                                    <Th>
                                        <span className="sr-only">Measure</span>
                                    </Th>
                                    <Th numeric>Goods</Th>
                                    <Th numeric>Rentals</Th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <Td>Net revenue</Td>
                                    <Td numeric>
                                        {formatMoney(summary.goods.revenue)}
                                    </Td>
                                    <Td numeric>
                                        {formatMoney(summary.rentals.revenue)}
                                    </Td>
                                </tr>
                                <tr>
                                    <Td>Direct costs</Td>
                                    <Td numeric>
                                        {formatMoney(summary.goods.costs)}
                                    </Td>
                                    <Td numeric>
                                        {formatMoney(summary.rentals.costs)}
                                    </Td>
                                </tr>
                                <tr className="font-semibold">
                                    <Td>Gross profit</Td>
                                    <Td numeric>
                                        {formatMoney(summary.goods.profit)}
                                    </Td>
                                    <Td numeric>
                                        {formatMoney(summary.rentals.profit)}
                                    </Td>
                                </tr>
                                <tr className="text-muted-foreground">
                                    <Td>Margin</Td>
                                    <Td numeric>
                                        {percent(summary.goods.margin)}
                                    </Td>
                                    <Td numeric>
                                        {percent(summary.rentals.margin)}
                                    </Td>
                                </tr>
                            </tbody>
                        </Table>
                    </Section>
                )}

                {includes.goods && (
                    <Section
                        title="Memo"
                        description="Not part of gross profit; shown for context."
                    >
                        <dl className="grid gap-2 text-sm">
                            <MemoRow
                                label="Discounts given"
                                value={formatMoney(memo.discounts)}
                                strong
                            />
                            <MemoRow
                                label="Price lists, contracts and categories"
                                value={formatMoney(memo.rule_discounts)}
                                indent
                            />
                            <MemoRow
                                label={
                                    memo.exempt_discounts > 0
                                        ? `By hand (${formatMoney(memo.exempt_discounts)} on exempt products)`
                                        : 'By hand'
                                }
                                value={formatMoney(memo.manual_discounts)}
                                indent
                            />
                            <MemoRow
                                label={`Pallet deposits held at ${formatDate(`${filters.to}T12:00:00`)} · a liability`}
                                value={formatMoney(memo.deposits_held)}
                                strong
                            />
                        </dl>
                    </Section>
                )}
            </div>
        </div>
    );
}

function GroupRows({
    group,
    lines,
    summary,
    comparison,
    cols,
    before,
    onOpen,
}: {
    group: (typeof groups)[number];
    lines: Props['lines'];
    summary: Summary;
    comparison: Summary | null;
    cols: number;
    before: ReactNode;
    onOpen: (key: LineKey, label: string) => void;
}) {
    return (
        <>
            {before}
            <tr>
                <th
                    scope="rowgroup"
                    colSpan={cols}
                    className="px-4 pt-5 pb-1.5 text-left text-xs font-semibold tracking-[0.04em] text-muted-foreground uppercase"
                >
                    {group.heading}
                </th>
            </tr>
            {lines.map((line) => (
                <tr key={line.key}>
                    <Td className="pl-7">
                        {line.label}
                        {lineNotes[line.key] && (
                            <span className="text-xs text-muted-foreground">
                                {' '}
                                · {lineNotes[line.key]}
                            </span>
                        )}
                    </Td>
                    <Td numeric>
                        <button
                            type="button"
                            className="rounded-sm underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            onClick={() => onOpen(line.key, line.label)}
                        >
                            {amount(summary.lines[line.key])}
                        </button>
                    </Td>
                    <Td numeric className="text-muted-foreground">
                        {share(summary.lines[line.key], summary.net_revenue)}
                    </Td>
                    {comparison && (
                        <Td numeric className="text-muted-foreground">
                            {amount(comparison.lines[line.key])}
                        </Td>
                    )}
                </tr>
            ))}
            <tr className="font-semibold">
                <Td>{group.subtotal}</Td>
                <Td numeric>{amount(subtotal(summary, group.key))}</Td>
                <Td numeric className="text-muted-foreground">
                    {share(subtotal(summary, group.key), summary.net_revenue)}
                </Td>
                {comparison && (
                    <Td numeric>{amount(subtotal(comparison, group.key))}</Td>
                )}
            </tr>
        </>
    );
}

function MemoRow({
    label,
    value,
    strong,
    indent,
}: {
    label: string;
    value: string;
    strong?: boolean;
    indent?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex justify-between gap-3',
                indent && 'pl-3 text-muted-foreground',
                strong && 'border-t border-secondary pt-2',
            )}
        >
            <dt>{label}</dt>
            <dd className={cn('figure', strong && 'font-semibold')}>{value}</dd>
        </div>
    );
}

// ------------------------------------------------------------------ trend

function TrendBars({ trend }: { trend: Props['trend'] }) {
    const max = Math.max(1, ...trend.map((t) => t.gross_profit));
    const last = trend.length - 1;

    return (
        <div
            role="img"
            aria-label={`Gross profit by month: ${trend
                .map(
                    (t) =>
                        `${t.label} ${formatMoney(t.gross_profit)} at ${percent(t.margin)}`,
                )
                .join(', ')}`}
        >
            <div className="grid h-48 grid-cols-6 items-end gap-2.5 border-b border-border">
                {trend.map((t, i) => (
                    <div
                        key={t.month}
                        className="flex h-full flex-col items-center justify-end gap-1.5"
                    >
                        <span
                            className={cn(
                                'text-xs',
                                i === last
                                    ? 'font-semibold'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {percent(t.margin)}
                        </span>
                        <div
                            className={cn(
                                'w-full max-w-11 rounded-t-sm',
                                i === last ? 'bg-primary' : 'bg-primary/30',
                            )}
                            style={{
                                height: `${Math.max(0, (t.gross_profit / max) * 100) * 0.8}%`,
                            }}
                        />
                    </div>
                ))}
            </div>
            <div
                aria-hidden
                className="mt-1.5 grid grid-cols-6 gap-2.5 text-center text-xs text-muted-foreground"
            >
                {trend.map((t, i) => (
                    <span
                        key={t.month}
                        className={cn(
                            i === last && 'font-semibold text-foreground',
                        )}
                    >
                        {t.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

function TrendTab({ trend }: { trend: Props['trend'] }) {
    return (
        <div className="flex flex-wrap items-start gap-5">
            <Section
                title="Gross profit by month"
                className="min-w-0 flex-[2_1_320px]"
            >
                <TrendBars trend={trend} />
            </Section>
            <Section
                title="By month"
                className="min-w-0 flex-[3_1_420px]"
                flush
            >
                <Table>
                    <thead>
                        <tr>
                            <Th>Month</Th>
                            <Th numeric>Net revenue</Th>
                            <Th numeric>Gross profit</Th>
                            <Th numeric>Margin</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {[...trend].reverse().map((t) => (
                            <tr key={t.month}>
                                <Td>
                                    {new Date(
                                        `${t.month}-01T12:00:00`,
                                    ).toLocaleDateString('en-CA', {
                                        month: 'long',
                                        year: 'numeric',
                                    })}
                                </Td>
                                <Td numeric>{formatMoney(t.net_revenue)}</Td>
                                <Td numeric>{formatMoney(t.gross_profit)}</Td>
                                <Td numeric>{percent(t.margin)}</Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Section>
        </div>
    );
}

// ----------------------------------------------------------- breakdowns

function MarginCell({ margin }: { margin: number | null }) {
    return (
        <Td>
            <div className="flex items-center gap-2.5">
                <div className="h-2 min-w-16 flex-1 rounded-full bg-secondary">
                    <div
                        className="h-2 rounded-full bg-primary"
                        style={{
                            width: `${Math.min(100, Math.max(0, margin ?? 0))}%`,
                        }}
                    />
                </div>
                <span className="figure w-12 text-right">
                    {percent(margin)}
                </span>
            </div>
        </Td>
    );
}

function MissingNote({ missing }: { missing: number }) {
    return missing > 0 ? (
        <div className="text-xs text-warning">
            {missing} {missing === 1 ? 'line has' : 'lines have'} no cost
        </div>
    ) : null;
}

function CategoriesTable({ rows }: { rows: Props['categories'] }) {
    return (
        <Section
            title="Goods sales by category"
            description="Before returns and inventory adjustments, most profitable first."
            flush
        >
            <Table minWidth={680}>
                <thead>
                    <tr>
                        <Th>Category</Th>
                        <Th numeric>Sales</Th>
                        <Th numeric>Cost</Th>
                        <Th numeric>Gross profit</Th>
                        <Th className="w-1/3">Margin</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <EmptyRow colSpan={5}>
                            No goods sold in this period.
                        </EmptyRow>
                    )}
                    {rows.map((r) => (
                        <tr key={r.category}>
                            <Td>
                                <div className="font-medium">{r.category}</div>
                                <MissingNote missing={r.missing} />
                            </Td>
                            <Td numeric>{formatMoney(r.sales)}</Td>
                            <Td numeric>{formatMoney(r.cost)}</Td>
                            <Td numeric className="font-semibold">
                                {formatMoney(r.profit)}
                            </Td>
                            <MarginCell margin={r.margin} />
                        </tr>
                    ))}
                </tbody>
            </Table>
        </Section>
    );
}

function ProductsTable({ rows }: { rows: Props['products'] }) {
    return (
        <Section
            title="Goods sales by product"
            description="Before returns, most profitable first. Quantities are in each product’s base unit."
            flush
        >
            <Table minWidth={820}>
                <thead>
                    <tr>
                        <Th>Product</Th>
                        <Th numeric>Sold</Th>
                        <Th numeric>Sales</Th>
                        <Th numeric>Cost</Th>
                        <Th numeric>Gross profit</Th>
                        <Th className="w-1/4">Margin</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <EmptyRow colSpan={6}>
                            No goods sold in this period.
                        </EmptyRow>
                    )}
                    {rows.map((r) => (
                        <tr key={r.product_id ?? 'none'}>
                            <Td>
                                <div className="font-medium">{r.name}</div>
                                <div className="text-xs text-muted-foreground">
                                    {[r.sku, r.category]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                                <MissingNote missing={r.missing} />
                            </Td>
                            <Td numeric>
                                {formatQuantity(r.quantity)}
                                {r.unit ? ` ${unitLabel(r.unit)}` : ''}
                            </Td>
                            <Td numeric>{formatMoney(r.sales)}</Td>
                            <Td numeric>{formatMoney(r.cost)}</Td>
                            <Td numeric className="font-semibold">
                                {formatMoney(r.profit)}
                            </Td>
                            <MarginCell margin={r.margin} />
                        </tr>
                    ))}
                </tbody>
            </Table>
        </Section>
    );
}

function CustomersTable({ rows }: { rows: Props['customers'] }) {
    return (
        <Section
            title="Goods sales by customer"
            description="Before returns: which accounts are profitable after their discounts."
            flush
        >
            <Table minWidth={860}>
                <thead>
                    <tr>
                        <Th>Account</Th>
                        <Th numeric>Orders</Th>
                        <Th numeric>Sales</Th>
                        <Th numeric>Discounts</Th>
                        <Th numeric>Gross profit</Th>
                        <Th className="w-1/4">Margin</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <EmptyRow colSpan={6}>
                            No goods sold in this period.
                        </EmptyRow>
                    )}
                    {rows.map((r) => (
                        <tr key={r.organization_id ?? 'walk-in'}>
                            <Td>
                                {r.organization_id !== null ? (
                                    <Link
                                        href={OrganizationController.show(
                                            r.organization_id,
                                        )}
                                        className="font-medium hover:underline"
                                    >
                                        {r.name}
                                    </Link>
                                ) : (
                                    <span className="font-medium">
                                        {r.name}
                                    </span>
                                )}
                                {r.price_list && (
                                    <div className="text-xs text-muted-foreground">
                                        {r.price_list}
                                    </div>
                                )}
                            </Td>
                            <Td numeric>{r.orders}</Td>
                            <Td numeric>{formatMoney(r.sales)}</Td>
                            <Td numeric className="text-muted-foreground">
                                {formatMoney(r.discounts)}
                            </Td>
                            <Td numeric className="font-semibold">
                                {formatMoney(r.profit)}
                            </Td>
                            <MarginCell margin={r.margin} />
                        </tr>
                    ))}
                </tbody>
            </Table>
        </Section>
    );
}

function UnitsTable({ rows }: { rows: Props['units'] }) {
    return (
        <Section
            title="Rentals by unit"
            description="Rental charges less maintenance. Waivers, delivery and extra charges belong to the rental, not a unit."
            flush
        >
            <Table minWidth={680}>
                <thead>
                    <tr>
                        <Th>Unit</Th>
                        <Th numeric>Rentals</Th>
                        <Th numeric>Rental charges</Th>
                        <Th numeric>Maintenance</Th>
                        <Th numeric>Gross profit</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <EmptyRow colSpan={5}>
                            No equipment went out or was serviced in this
                            period.
                        </EmptyRow>
                    )}
                    {rows.map((r) => (
                        <tr key={r.unit_id}>
                            <Td>
                                <Link
                                    href={FleetController.show(r.unit_id)}
                                    className="font-medium hover:underline"
                                >
                                    {r.name}
                                </Link>
                                <div className="figure text-xs text-muted-foreground">
                                    {r.serial}
                                </div>
                            </Td>
                            <Td numeric>{r.rentals}</Td>
                            <Td numeric>{formatMoney(r.revenue)}</Td>
                            <Td numeric>{formatMoney(r.maintenance)}</Td>
                            <Td
                                numeric
                                className={cn(
                                    'font-semibold',
                                    r.profit < 0 && 'text-danger',
                                )}
                            >
                                {amount(r.profit)}
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
        </Section>
    );
}

// ----------------------------------------------------------------- detail

function DetailDialog({
    line,
    label,
    period,
    query,
    onClose,
}: {
    line: LineKey | 'missing';
    label: string;
    period: string;
    query: Record<string, string | number>;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<DetailRow[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const controller = new AbortController();

        getJson<{ rows: DetailRow[] }>(
            ProfitReportController.detail.url(),
            { ...query, line },
            controller.signal,
        )
            .then((data) => setRows(data.rows))
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    setFailed(Boolean(error));
                }
            });

        return () => controller.abort();
    }, [line, query]);

    const total = rows?.reduce((sum, r) => sum + r.amount, 0) ?? 0;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{label}</DialogTitle>
                    <DialogDescription>
                        {period}
                        {rows && rows.length > 0
                            ? ` · ${rows.length === 200 ? 'latest 200' : rows.length} ${rows.length === 1 ? 'entry' : 'entries'} · ${amount(total)}`
                            : ''}
                    </DialogDescription>
                </DialogHeader>
                <div className="max-h-[60vh] overflow-y-auto">
                    <Table minWidth={560}>
                        <thead>
                            <tr>
                                <Th>Date</Th>
                                <Th>Reference</Th>
                                <Th>Details</Th>
                                <Th numeric>Amount</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {failed && (
                                <EmptyRow colSpan={4}>
                                    Couldn’t load these. Try again.
                                </EmptyRow>
                            )}
                            {!failed && rows === null && (
                                <EmptyRow colSpan={4}>Loading…</EmptyRow>
                            )}
                            {rows?.length === 0 && (
                                <EmptyRow colSpan={4}>
                                    Nothing in this period.
                                </EmptyRow>
                            )}
                            {rows?.map((r, i) => (
                                <tr key={`${r.reference}-${i}`}>
                                    <Td className="whitespace-nowrap">
                                        {formatDate(`${r.date}T12:00:00`)}
                                    </Td>
                                    <Td className="figure whitespace-nowrap">
                                        {r.href ? (
                                            <Link
                                                href={r.href}
                                                className="hover:underline"
                                            >
                                                {r.reference}
                                            </Link>
                                        ) : (
                                            r.reference
                                        )}
                                    </Td>
                                    <Td>
                                        <div>{r.description}</div>
                                        {r.who && (
                                            <div className="text-xs text-muted-foreground">
                                                {r.who}
                                            </div>
                                        )}
                                    </Td>
                                    <Td numeric>{amount(r.amount)}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            </DialogContent>
        </Dialog>
    );
}
