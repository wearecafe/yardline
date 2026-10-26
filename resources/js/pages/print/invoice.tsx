import { Head } from '@inertiajs/react';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import { PrintBar } from '@/components/yard/print-bar';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';

type Props = {
    invoice: {
        number: string;
        status: string;
        terms: string;
        issued_on: string;
        due_on: string;
        balance_cents: number;
    };
    order: {
        id: number;
        number: string;
        po_number: string | null;
        bill_to: string[];
        ship_to: {
            line1?: string | null;
            city?: string | null;
            postal_code?: string | null;
        } | null;
        fulfillment: string;
        yard: string;
        lines: {
            id: number;
            sku: string | null;
            description: string;
            quantity: string;
            unit: string;
            unit_price_cents: number;
            line_total_cents: number;
            deposit_cents: number;
            pallets: number;
        }[];
        delivery_fee_cents: number;
        subtotal_cents: number;
        tax_cents: number;
        deposit_cents: number;
        total_cents: number;
        paid_cents: number;
    };
    business: { name: string };
};

export default function InvoicePage({ invoice, order, business }: Props) {
    return (
        <>
            <Head title={`Invoice ${invoice.number}`} />
            <PrintBar
                back={OrderController.show.url(order.id)}
                backLabel={order.number}
            />
            <main className="mx-auto max-w-3xl bg-white p-8 text-[13px] text-black">
                <header className="flex flex-wrap items-start justify-between gap-6 border-b-2 border-black pb-4">
                    <div>
                        <div className="text-2xl font-bold">
                            {business.name}
                        </div>
                        <div className="text-lg">Invoice {invoice.number}</div>
                        {invoice.status === 'void' && (
                            <div className="font-bold text-red-700">VOID</div>
                        )}
                    </div>
                    <dl className="grid grid-cols-[auto_auto] gap-x-3 text-right">
                        <dt>Issued</dt>
                        <dd>{formatDate(invoice.issued_on)}</dd>
                        <dt>Terms</dt>
                        <dd>{invoice.terms}</dd>
                        <dt>Due</dt>
                        <dd className="font-semibold">
                            {formatDate(invoice.due_on)}
                        </dd>
                        <dt>Order</dt>
                        <dd className="font-mono">{order.number}</dd>
                        {order.po_number && (
                            <>
                                <dt>PO</dt>
                                <dd>{order.po_number}</dd>
                            </>
                        )}
                    </dl>
                </header>
                <div className="mt-4 grid grid-cols-2 gap-6">
                    <div>
                        <div className="text-xs font-semibold uppercase">
                            Bill to
                        </div>
                        {order.bill_to.map((l) => (
                            <div key={l}>{l}</div>
                        ))}
                    </div>
                    <div>
                        <div className="text-xs font-semibold uppercase">
                            {order.fulfillment === 'delivery'
                                ? 'Delivered to'
                                : 'Picked up at'}
                        </div>
                        <div>
                            {order.fulfillment === 'delivery' && order.ship_to
                                ? [
                                      order.ship_to.line1,
                                      order.ship_to.city,
                                      order.ship_to.postal_code,
                                  ]
                                      .filter(Boolean)
                                      .join(', ')
                                : order.yard}
                        </div>
                    </div>
                </div>
                <table className="mt-5 w-full border-collapse">
                    <thead>
                        <tr className="border-b border-black text-left text-xs">
                            <th className="py-1.5">Item</th>
                            <th className="text-right">Qty</th>
                            <th className="text-right">Price</th>
                            <th className="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {order.lines.map((l) => (
                            <tr key={l.id} className="border-b border-gray-200">
                                <td className="py-1.5">
                                    {l.description}
                                    {l.sku && (
                                        <span className="ml-1 font-mono text-xs text-gray-600">
                                            {l.sku}
                                        </span>
                                    )}
                                    {l.pallets > 0 && (
                                        <div className="text-xs text-gray-600">
                                            {l.pallets} pallet{' '}
                                            {l.pallets === 1
                                                ? 'deposit'
                                                : 'deposits'}
                                        </div>
                                    )}
                                </td>
                                <td className="text-right font-mono">
                                    {formatQuantity(l.quantity)}{' '}
                                    {unitLabel(l.unit)}
                                </td>
                                <td className="text-right font-mono">
                                    {formatMoney(l.unit_price_cents)}
                                </td>
                                <td className="text-right font-mono">
                                    {formatMoney(l.line_total_cents)}
                                </td>
                            </tr>
                        ))}
                        {order.delivery_fee_cents > 0 && (
                            <tr className="border-b border-gray-200">
                                <td className="py-1.5">Delivery</td>
                                <td />
                                <td />
                                <td className="text-right font-mono">
                                    {formatMoney(order.delivery_fee_cents)}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
                <dl className="mt-4 ml-auto grid max-w-xs grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                    <dt>Subtotal</dt>
                    <dd className="text-right font-mono">
                        {formatMoney(order.subtotal_cents)}
                    </dd>
                    <dt>Tax</dt>
                    <dd className="text-right font-mono">
                        {formatMoney(order.tax_cents)}
                    </dd>
                    {order.deposit_cents > 0 && (
                        <>
                            <dt>Refundable deposits</dt>
                            <dd className="text-right font-mono">
                                {formatMoney(order.deposit_cents)}
                            </dd>
                        </>
                    )}
                    <dt className="border-t border-black pt-1 font-bold">
                        Total
                    </dt>
                    <dd className="border-t border-black pt-1 text-right font-mono font-bold">
                        {formatMoney(order.total_cents)}
                    </dd>
                    {order.paid_cents > 0 && (
                        <>
                            <dt>Paid</dt>
                            <dd className="text-right font-mono">
                                −{formatMoney(order.paid_cents)}
                            </dd>
                        </>
                    )}
                    <dt className="font-bold">Balance due</dt>
                    <dd className="text-right font-mono font-bold">
                        {formatMoney(invoice.balance_cents)}
                    </dd>
                </dl>
            </main>
        </>
    );
}
