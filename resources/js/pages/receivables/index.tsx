import { Head, Link } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import ReceivablesController from '@/actions/App/Http/Controllers/ReceivablesController';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import { Chip } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import {
    PageBody,
    PageHeader,
    Section,
    Stat,
    StatGrid,
} from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Bucket = 'current' | 'd30' | 'd60' | 'd90' | 'over';

type Row = {
    organization_id: number;
    name: string;
    account_number: string;
    terms: string;
    credit_hold: boolean;
    buckets: Record<Bucket, number>;
    owing_cents: number;
    credit_cents: number;
    net_cents: number;
    oldest_days: number;
    invoices: number;
};

type Props = {
    rows: Row[];
    labels: Record<Bucket, string>;
    totals: Record<Bucket, number>;
    stats: {
        owing_cents: number;
        overdue_cents: number;
        credits_cents: number;
        pallets_out: number;
        walk_in_cents: number;
    };
    credits: {
        id: number;
        number: string;
        kind: string;
        who: string;
        reason: string | null;
        issued_on: string;
        total_cents: number;
        remaining_cents: number;
    }[];
};

const buckets: Bucket[] = ['current', 'd30', 'd60', 'd90', 'over'];

/**
 * Receivables: what every account owes, by age, with statements.
 */
export default function Receivables({
    rows,
    labels,
    totals,
    stats,
    credits,
}: Props) {
    return (
        <>
            <Head title="Receivables" />
            <PageBody>
                <PageHeader
                    title="Receivables"
                    description="Open invoices on account, by how late they are, less unused credits."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={ReturnController.index()}>
                                Returns and credits
                            </Link>
                        </Button>
                    }
                />
                <StatGrid>
                    <Stat
                        label="Owing on account"
                        value={formatMoney(stats.owing_cents)}
                    />
                    <Stat
                        label="Overdue"
                        value={formatMoney(stats.overdue_cents)}
                        tone={stats.overdue_cents > 0 ? 'danger' : undefined}
                    />
                    <Stat
                        label="Unused credits"
                        value={formatMoney(stats.credits_cents)}
                    />
                    <Stat
                        label="Pallets out on deposit"
                        value={stats.pallets_out}
                    />
                </StatGrid>

                <Section title="Aging" flush>
                    <Table minWidth={900}>
                        <thead>
                            <tr>
                                <Th>Account</Th>
                                {buckets.map((b) => (
                                    <Th key={b} numeric>
                                        {labels[b]}
                                    </Th>
                                ))}
                                <Th numeric>Credits</Th>
                                <Th numeric>Net</Th>
                                <Th>
                                    <span className="sr-only">Statement</span>
                                </Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <EmptyRow colSpan={9}>
                                    Nothing owing on account.
                                </EmptyRow>
                            )}
                            {rows.map((row) => (
                                <tr key={row.organization_id}>
                                    <Td>
                                        <Link
                                            href={OrganizationController.show(
                                                row.organization_id,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                            <span className="figure">
                                                {row.account_number}
                                            </span>{' '}
                                            · {row.terms} · {row.invoices} open
                                            {row.credit_hold && (
                                                <Chip tone="danger">
                                                    On hold
                                                </Chip>
                                            )}
                                        </div>
                                    </Td>
                                    {buckets.map((b) => (
                                        <Td
                                            key={b}
                                            numeric
                                            className={cn(
                                                row.buckets[b] === 0 &&
                                                    'text-muted-foreground',
                                                b !== 'current' &&
                                                    row.buckets[b] > 0 &&
                                                    'font-semibold text-danger',
                                            )}
                                        >
                                            {row.buckets[b]
                                                ? formatMoney(row.buckets[b])
                                                : '—'}
                                        </Td>
                                    ))}
                                    <Td numeric className="text-success">
                                        {row.credit_cents
                                            ? `−${formatMoney(row.credit_cents)}`
                                            : '—'}
                                    </Td>
                                    <Td numeric className="font-semibold">
                                        {formatMoney(row.net_cents)}
                                    </Td>
                                    <Td className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={ReceivablesController.statement(
                                                    row.organization_id,
                                                )}
                                            >
                                                Statement
                                            </Link>
                                        </Button>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 border-border font-semibold">
                                    <Td>Total</Td>
                                    {buckets.map((b) => (
                                        <Td key={b} numeric>
                                            {formatMoney(totals[b])}
                                        </Td>
                                    ))}
                                    <Td numeric />
                                    <Td numeric>
                                        {formatMoney(stats.owing_cents)}
                                    </Td>
                                    <Td />
                                </tr>
                            </tfoot>
                        )}
                    </Table>
                </Section>
                {stats.walk_in_cents > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Walk-in orders waiting on payment:{' '}
                        {formatMoney(stats.walk_in_cents)}.
                    </p>
                )}

                <Section title="Recent credits" flush>
                    <Table>
                        <tbody>
                            {credits.length === 0 && (
                                <EmptyRow colSpan={4}>
                                    No credit notes yet.
                                </EmptyRow>
                            )}
                            {credits.map((c) => (
                                <tr key={c.id}>
                                    <Td>
                                        <Link
                                            href={ReturnController.show(c.id)}
                                            className="figure font-medium hover:underline"
                                        >
                                            {c.number}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {formatDate(c.issued_on)}
                                        </div>
                                    </Td>
                                    <Td>
                                        {c.who}
                                        <div className="text-xs text-muted-foreground">
                                            {c.reason}
                                        </div>
                                    </Td>
                                    <Td numeric>
                                        {formatMoney(c.total_cents)}
                                    </Td>
                                    <Td
                                        numeric
                                        className="text-muted-foreground"
                                    >
                                        {c.remaining_cents
                                            ? `${formatMoney(c.remaining_cents)} unused`
                                            : 'Used'}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Section>
            </PageBody>
        </>
    );
}

Receivables.layout = {
    breadcrumbs: [
        { title: 'Receivables', href: ReceivablesController.index() },
    ],
};
