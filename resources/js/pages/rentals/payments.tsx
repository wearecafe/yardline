import { Head, Link } from '@inertiajs/react';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import RentalPaymentController from '@/actions/App/Http/Controllers/Rentals/RentalPaymentController';
import { Chip, type ChipTone } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import {
    PageBody,
    PageHeader,
    Section,
    Stat,
    StatGrid,
} from '@/components/yard/page-header';
import { RentalStatusChip } from '@/components/yard/rental-status';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import type { RentalStatus } from '@/types';

type Props = {
    stats: { month_cents: number; held_cents: number; owing_cents: number };
    payments: {
        id: number;
        kind: string;
        tender: string;
        amount_cents: number;
        reference: string | null;
        rental_id: number;
        number: string;
        customer: string;
        succeeded_at: string | null;
        recorded_by: string | null;
    }[];
    held: {
        id: number;
        number: string;
        customer: string;
        deposit_cents: number;
        ends_at: string;
        status: RentalStatus;
    }[];
    owing: {
        id: number;
        number: string;
        customer: string;
        balance_cents: number;
        status: RentalStatus;
    }[];
};

const kinds: Record<string, { label: string; tone: ChipTone }> = {
    charge: { label: 'Payment', tone: 'success' },
    deposit_hold: { label: 'Deposit hold', tone: 'info' },
    deposit_capture: { label: 'Deposit kept', tone: 'warning' },
    deposit_release: { label: 'Deposit released', tone: 'neutral' },
    refund: { label: 'Refund', tone: 'danger' },
};

const tenders: Record<string, string> = {
    card_on_file: 'Card on file',
    payment_link: 'Payment link',
    card_terminal: 'Card terminal',
    cash: 'Cash',
    cheque: 'Cheque',
    etransfer: 'e-Transfer',
};

function RentalLink({ id, number }: { id: number; number: string }) {
    return (
        <Link
            href={RentalController.show(id)}
            className="figure font-medium hover:underline"
        >
            {number}
        </Link>
    );
}

/**
 * Rental money only. Rentals are billed separately from goods and sync to
 * QuickBooks Online.
 */
export default function RentalPayments({
    stats,
    payments,
    held,
    owing,
}: Props) {
    return (
        <>
            <Head title="Rental payments" />
            <PageBody>
                <PageHeader
                    title="Rental payments"
                    description="Prepaid rentals, deposits on hold and what's still owing."
                />
                <StatGrid>
                    <Stat
                        label="Taken this month"
                        value={formatMoney(stats.month_cents)}
                    />
                    <Stat
                        label="Deposits on hold"
                        value={formatMoney(stats.held_cents)}
                    />
                    <Stat
                        label="Owing"
                        value={formatMoney(stats.owing_cents)}
                        tone={stats.owing_cents > 0 ? 'warning' : undefined}
                    />
                </StatGrid>

                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        title="Payments"
                        flush
                        className="min-w-0 flex-[999_1_560px]"
                    >
                        <Table minWidth={640}>
                            <thead>
                                <tr>
                                    <Th>When</Th>
                                    <Th>Rental</Th>
                                    <Th>Type</Th>
                                    <Th numeric>Amount</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {payments.length === 0 && (
                                    <EmptyRow colSpan={4}>
                                        No rental payments yet.
                                    </EmptyRow>
                                )}
                                {payments.map((p) => {
                                    const kind = kinds[p.kind] ?? {
                                        label: p.kind,
                                        tone: 'neutral' as const,
                                    };

                                    return (
                                        <tr key={p.id}>
                                            <Td className="whitespace-nowrap text-subtle">
                                                {p.succeeded_at
                                                    ? formatDateTime(
                                                          p.succeeded_at,
                                                      )
                                                    : '—'}
                                            </Td>
                                            <Td>
                                                <RentalLink
                                                    id={p.rental_id}
                                                    number={p.number}
                                                />
                                                <div className="text-xs text-muted-foreground">
                                                    {p.customer}
                                                </div>
                                            </Td>
                                            <Td>
                                                <Chip tone={kind.tone}>
                                                    {kind.label}
                                                </Chip>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {tenders[p.tender] ??
                                                        p.tender}
                                                    {p.recorded_by
                                                        ? ` · ${p.recorded_by}`
                                                        : ''}
                                                </div>
                                            </Td>
                                            <Td numeric>
                                                {p.kind === 'refund'
                                                    ? `−${formatMoney(p.amount_cents)}`
                                                    : formatMoney(
                                                          p.amount_cents,
                                                      )}
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </Section>

                    <div className="flex min-w-0 flex-[1_1_320px] flex-col gap-5">
                        <Section title="Owing" flush>
                            <Table>
                                <tbody>
                                    {owing.length === 0 && (
                                        <EmptyRow colSpan={2}>
                                            Nothing owing.
                                        </EmptyRow>
                                    )}
                                    {owing.map((r) => (
                                        <tr key={r.id}>
                                            <Td>
                                                <RentalLink
                                                    id={r.id}
                                                    number={r.number}
                                                />{' '}
                                                <RentalStatusChip
                                                    status={r.status}
                                                />
                                                <div className="text-xs text-muted-foreground">
                                                    {r.customer}
                                                </div>
                                            </Td>
                                            <Td numeric>
                                                {formatMoney(r.balance_cents)}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Section>
                        <Section title="Deposits on hold" flush>
                            <Table>
                                <tbody>
                                    {held.length === 0 && (
                                        <EmptyRow colSpan={2}>
                                            No deposits on hold.
                                        </EmptyRow>
                                    )}
                                    {held.map((r) => (
                                        <tr key={r.id}>
                                            <Td>
                                                <RentalLink
                                                    id={r.id}
                                                    number={r.number}
                                                />
                                                <div className="text-xs text-muted-foreground">
                                                    {r.customer} · back{' '}
                                                    {formatDate(r.ends_at)}
                                                </div>
                                            </Td>
                                            <Td numeric>
                                                {formatMoney(r.deposit_cents)}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Section>
                    </div>
                </div>
            </PageBody>
        </>
    );
}

RentalPayments.layout = {
    breadcrumbs: [
        { title: 'Rental payments', href: RentalPaymentController.url() },
    ],
};
