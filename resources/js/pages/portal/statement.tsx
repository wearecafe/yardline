import { Head } from '@inertiajs/react';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import { PortalLayout } from '@/components/yard/portal-layout';
import { formatDate, formatMoney } from '@/lib/format';

type Statement = {
    date: string;
    invoices: {
        id: number;
        number: string;
        order_number: string;
        po_number: string | null;
        issued_on: string;
        due_on: string;
        balance_cents: number;
        days_overdue: number;
    }[];
    credits: {
        id: number;
        number: string;
        reason: string | null;
        remaining_cents: number;
    }[];
    buckets: Record<string, number>;
    bucket_labels: Record<string, string>;
    balance_cents: number;
    pallets_out: number;
};

export default function PortalStatement({
    statement: s,
}: {
    statement: Statement;
}) {
    return (
        <PortalLayout active="statements">
            <Head title="Statement" />
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-3xl font-bold">Statement</h1>
                    <p className="text-muted-foreground">
                        As of {formatDate(s.date)}
                    </p>
                </div>
                <a
                    href={PortalController.statementPdf.url()}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded-lg border border-input px-4 py-2.5 font-semibold hover:bg-accent"
                >
                    Download PDF
                </a>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                {Object.entries(s.bucket_labels).map(([key, label]) => (
                    <div
                        key={key}
                        className="rounded-[10px] border border-border bg-card p-3"
                    >
                        <div className="text-xs text-muted-foreground">
                            {label}
                        </div>
                        <div className="figure text-lg font-semibold">
                            {formatMoney(s.buckets[key] ?? 0)}
                        </div>
                    </div>
                ))}
            </div>
            <section className="overflow-x-auto rounded-[10px] border border-border bg-card">
                <table className="w-full min-w-[560px] border-collapse text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th className="px-5 py-2.5 font-medium">Invoice</th>
                            <th className="px-3 py-2.5 font-medium">Order</th>
                            <th className="px-3 py-2.5 font-medium">Due</th>
                            <th className="px-5 py-2.5 text-right font-medium">
                                Balance
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {s.invoices.length === 0 && (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-5 py-6 text-center text-muted-foreground"
                                >
                                    Nothing owing. Thanks!
                                </td>
                            </tr>
                        )}
                        {s.invoices.map((i) => (
                            <tr
                                key={i.id}
                                className="border-b border-secondary last:border-0"
                            >
                                <td className="figure px-5 py-3">{i.number}</td>
                                <td className="px-3 py-3">
                                    {i.order_number}
                                    {i.po_number ? ` · ${i.po_number}` : ''}
                                </td>
                                <td className="px-3 py-3">
                                    {formatDate(i.due_on)}
                                    {i.days_overdue > 0 && (
                                        <span className="ml-1 font-semibold text-danger">
                                            {i.days_overdue} days late
                                        </span>
                                    )}
                                </td>
                                <td className="figure px-5 py-3 text-right">
                                    {formatMoney(i.balance_cents)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
            {s.credits.filter((c) => c.remaining_cents > 0).length > 0 && (
                <p className="text-sm text-success">
                    Credit on your account:{' '}
                    {formatMoney(
                        s.credits.reduce(
                            (sum, c) => sum + c.remaining_cents,
                            0,
                        ),
                    )}
                </p>
            )}
            <p className="text-right text-xl font-bold">
                Balance due: {formatMoney(s.balance_cents)}
            </p>
            {s.pallets_out > 0 && (
                <p className="text-right text-sm text-muted-foreground">
                    {s.pallets_out} pallets out on deposit.
                </p>
            )}
        </PortalLayout>
    );
}
