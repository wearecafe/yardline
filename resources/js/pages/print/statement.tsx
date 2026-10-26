import { Head, router } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import ReceivablesController from '@/actions/App/Http/Controllers/ReceivablesController';
import { PrintBar } from '@/components/yard/print-bar';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';

type Statement = {
    date: string;
    organization: {
        id: number;
        name: string;
        account_number: string;
        email: string | null;
        address: string[];
        terms: string;
        credit_limit_cents: number;
    };
    invoices: {
        id: number;
        number: string;
        order_id: number;
        order_number: string;
        po_number: string | null;
        issued_on: string;
        due_on: string;
        total_cents: number;
        paid_cents: number;
        balance_cents: number;
        days_overdue: number;
    }[];
    payments: {
        id: number;
        date: string | null;
        order_number: string;
        tender: string;
        reference: string | null;
        amount_cents: number;
    }[];
    credits: {
        id: number;
        number: string;
        issued_on: string;
        reason: string | null;
        total_cents: number;
        remaining_cents: number;
    }[];
    buckets: Record<string, number>;
    bucket_labels: Record<string, string>;
    owing_cents: number;
    credit_cents: number;
    balance_cents: number;
    pallets_out: number;
};

/**
 * An account statement, to print, download or email.
 */
export default function StatementPage({
    statement: s,
    business,
    canEmail,
}: {
    statement: Statement;
    business: string;
    canEmail: boolean;
}) {
    return (
        <>
            <Head title={`Statement · ${s.organization.name}`} />
            <PrintBar
                back={OrganizationController.show.url(s.organization.id)}
                backLabel={s.organization.name}
            >
                <Button variant="outline" asChild>
                    <a
                        href={ReceivablesController.pdf.url(s.organization.id)}
                        target="_blank"
                        rel="noreferrer"
                    >
                        PDF
                    </a>
                </Button>
                {canEmail && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                ReceivablesController.email.url(
                                    s.organization.id,
                                ),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Mail /> Email to {s.organization.email}
                    </Button>
                )}
            </PrintBar>
            <main className="mx-auto max-w-3xl bg-white p-8 text-[13px] text-black">
                <header className="flex flex-wrap items-start justify-between gap-6 border-b-2 border-black pb-4">
                    <div>
                        <div className="text-2xl font-bold">{business}</div>
                        <div>Statement of account · {formatDate(s.date)}</div>
                    </div>
                    <div className="text-right">
                        <div className="font-semibold">
                            {s.organization.name}
                        </div>
                        <div className="font-mono">
                            {s.organization.account_number}
                        </div>
                        {s.organization.address.map((line) => (
                            <div key={line}>{line}</div>
                        ))}
                        <div>Terms: {s.organization.terms}</div>
                    </div>
                </header>

                <h2 className="mt-5 mb-2 font-semibold">Open invoices</h2>
                <table className="w-full border-collapse">
                    <thead>
                        <tr className="border-b border-black text-left text-xs">
                            <th className="py-1.5">Invoice</th>
                            <th>Order / PO</th>
                            <th>Issued</th>
                            <th>Due</th>
                            <th className="text-right">Amount</th>
                            <th className="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        {s.invoices.length === 0 && (
                            <tr>
                                <td colSpan={6} className="py-2 text-gray-600">
                                    Nothing open.
                                </td>
                            </tr>
                        )}
                        {s.invoices.map((i) => (
                            <tr key={i.id} className="border-b border-gray-200">
                                <td className="py-1.5 font-mono">{i.number}</td>
                                <td>
                                    {i.order_number}
                                    {i.po_number ? ` · ${i.po_number}` : ''}
                                </td>
                                <td>{formatDate(i.issued_on)}</td>
                                <td>
                                    {formatDate(i.due_on)}
                                    {i.days_overdue > 0 && (
                                        <span className="ml-1 font-semibold text-red-700">
                                            {i.days_overdue}d late
                                        </span>
                                    )}
                                </td>
                                <td className="text-right font-mono">
                                    {formatMoney(i.total_cents)}
                                </td>
                                <td className="text-right font-mono">
                                    {formatMoney(i.balance_cents)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {s.credits.length > 0 && (
                    <>
                        <h2 className="mt-5 mb-2 font-semibold">Credits</h2>
                        <table className="w-full border-collapse">
                            <tbody>
                                {s.credits.map((c) => (
                                    <tr
                                        key={c.id}
                                        className="border-b border-gray-200"
                                    >
                                        <td className="py-1.5 font-mono">
                                            {c.number}
                                        </td>
                                        <td>{formatDate(c.issued_on)}</td>
                                        <td>{c.reason}</td>
                                        <td className="text-right font-mono">
                                            {formatMoney(c.total_cents)}
                                        </td>
                                        <td className="text-right font-mono">
                                            {c.remaining_cents
                                                ? `${formatMoney(c.remaining_cents)} unused`
                                                : 'used'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}

                {s.payments.length > 0 && (
                    <>
                        <h2 className="mt-5 mb-2 font-semibold">
                            Payments, last 60 days
                        </h2>
                        <table className="w-full border-collapse">
                            <tbody>
                                {s.payments.map((p) => (
                                    <tr
                                        key={p.id}
                                        className="border-b border-gray-200"
                                    >
                                        <td className="py-1.5">
                                            {formatDate(p.date)}
                                        </td>
                                        <td>{p.order_number}</td>
                                        <td>
                                            {p.tender}
                                            {p.reference
                                                ? ` · ${p.reference}`
                                                : ''}
                                        </td>
                                        <td className="text-right font-mono">
                                            {formatMoney(p.amount_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}

                <table className="mt-6 w-full border-collapse text-center">
                    <thead>
                        <tr>
                            {Object.entries(s.bucket_labels).map(
                                ([k, label]) => (
                                    <th
                                        key={k}
                                        className="border border-gray-300 py-1 text-xs"
                                    >
                                        {label}
                                    </th>
                                ),
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            {Object.keys(s.bucket_labels).map((k) => (
                                <td
                                    key={k}
                                    className="border border-gray-300 py-1.5 font-mono"
                                >
                                    {formatMoney(s.buckets[k] ?? 0)}
                                </td>
                            ))}
                        </tr>
                    </tbody>
                </table>
                <p className="mt-4 text-right text-lg font-bold">
                    Balance due: {formatMoney(s.balance_cents)}
                </p>
                {s.pallets_out > 0 && (
                    <p className="mt-1 text-right text-gray-600">
                        Pallets out on deposit: {s.pallets_out}. Bring them back
                        for a credit.
                    </p>
                )}
            </main>
        </>
    );
}
