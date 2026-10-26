import { Head, Link } from '@inertiajs/react';
import CounterController from '@/actions/App/Http/Controllers/CounterController';
import { PrintBar } from '@/components/yard/print-bar';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';

type Props = {
    order: {
        id: number;
        number: string;
        invoice_number: string | null;
        created_at: string;
        who: string;
        po_number: string | null;
        settlement: string | null;
        yard: { name: string; address: string };
        lines: {
            id: number;
            description: string;
            quantity: string;
            unit: string;
            unit_price_cents: number;
            line_total_cents: number;
            deposit_cents: number;
        }[];
        subtotal_cents: number;
        tax_cents: number;
        deposit_cents: number;
        total_cents: number;
        payments: {
            tender: string;
            amount_cents: number;
            reference: string | null;
        }[];
        balance_cents: number;
        cashier: string | null;
    };
    change_cents: number | null;
    business: { name: string };
};

/**
 * A till receipt, narrow for a receipt printer.
 */
export default function Receipt({ order, change_cents, business }: Props) {
    return (
        <>
            <Head title={`Receipt ${order.number}`} />
            <PrintBar back={CounterController.show.url()} backLabel="Counter">
                <Button variant="outline" asChild>
                    <Link href={CounterController.show.url()}>Next sale</Link>
                </Button>
            </PrintBar>
            {change_cents !== null && change_cents > 0 && (
                <div className="mx-auto mt-4 max-w-sm rounded-lg bg-success-soft px-4 py-3 text-center text-lg font-bold text-success print:hidden">
                    Change due: {formatMoney(change_cents)}
                </div>
            )}
            <main className="mx-auto max-w-[320px] bg-white p-4 font-mono text-[12px] text-black">
                <div className="text-center">
                    <div className="text-base font-bold">{business.name}</div>
                    <div>{order.yard.name}</div>
                    {order.yard.address && <div>{order.yard.address}</div>}
                    <div className="mt-1">
                        {formatDateTime(order.created_at)}
                    </div>
                    <div>
                        {order.number}
                        {order.invoice_number
                            ? ` · ${order.invoice_number}`
                            : ''}
                    </div>
                    <div>{order.who}</div>
                    {order.po_number && <div>PO {order.po_number}</div>}
                </div>
                <div className="my-2 border-t border-dashed border-black" />
                {order.lines.map((l) => (
                    <div key={l.id} className="mb-1">
                        <div>{l.description}</div>
                        <div className="flex justify-between">
                            <span>
                                {formatQuantity(l.quantity)} {unitLabel(l.unit)}{' '}
                                @ {formatMoney(l.unit_price_cents)}
                            </span>
                            <span>{formatMoney(l.line_total_cents)}</span>
                        </div>
                    </div>
                ))}
                <div className="my-2 border-t border-dashed border-black" />
                <div className="flex justify-between">
                    <span>Subtotal</span>
                    <span>{formatMoney(order.subtotal_cents)}</span>
                </div>
                <div className="flex justify-between">
                    <span>HST</span>
                    <span>{formatMoney(order.tax_cents)}</span>
                </div>
                {order.deposit_cents > 0 && (
                    <div className="flex justify-between">
                        <span>Deposits</span>
                        <span>{formatMoney(order.deposit_cents)}</span>
                    </div>
                )}
                <div className="flex justify-between text-sm font-bold">
                    <span>Total</span>
                    <span>{formatMoney(order.total_cents)}</span>
                </div>
                {order.payments.map((p, i) => (
                    <div key={i} className="flex justify-between">
                        <span>{p.tender}</span>
                        <span>{formatMoney(p.amount_cents)}</span>
                    </div>
                ))}
                {change_cents !== null && change_cents > 0 && (
                    <div className="flex justify-between">
                        <span>Change</span>
                        <span>{formatMoney(change_cents)}</span>
                    </div>
                )}
                {order.settlement === 'account' && (
                    <div className="mt-1">
                        Charged to account. {formatMoney(order.balance_cents)}{' '}
                        on your statement.
                    </div>
                )}
                <div className="my-2 border-t border-dashed border-black" />
                <div className="text-center">
                    {order.cashier && <div>Served by {order.cashier}</div>}
                    <div>Returns within 30 days with this receipt.</div>
                    <div>Thank you!</div>
                </div>
            </main>
        </>
    );
}
