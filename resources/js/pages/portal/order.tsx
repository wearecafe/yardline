import { Head, Link, router } from '@inertiajs/react';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import { PortalLayout } from '@/components/yard/portal-layout';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { statusTone } from './home';

type Props = {
    order: {
        id: number;
        number: string;
        status: string;
        po_number: string | null;
        delivery_address: {
            line1?: string | null;
            city?: string | null;
        } | null;
        fulfillment: string;
        lines: {
            id: number;
            description: string;
            quantity: string;
            unit: string;
            unit_price_cents: number;
            line_total_cents: number;
            shipped_quantity: string;
        }[];
        total_cents: number;
        balance_cents: number;
        invoice: { number: string; due_on: string; status: string } | null;
        shipments: {
            number: string;
            status: string;
            truck: string | null;
            eta: string | null;
            delivered_at: string | null;
            pod_name: string | null;
        }[];
    };
};

export default function PortalOrder({ order }: Props) {
    return (
        <PortalLayout active="orders">
            <Head title={`Order ${order.number}`} />
            <div>
                <Link
                    href={PortalController.orders.url()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    Orders
                </Link>
                <h1 className="flex flex-wrap items-center gap-3 text-3xl font-bold">
                    Order {order.number}
                    <span
                        className={cn(
                            'rounded-full px-2.5 py-1 text-sm font-semibold',
                            statusTone(order.status),
                        )}
                    >
                        {order.status}
                    </span>
                </h1>
                <p className="text-muted-foreground">
                    {order.fulfillment === 'delivery' &&
                    order.delivery_address?.line1
                        ? `Delivery to ${[order.delivery_address.line1, order.delivery_address.city].filter(Boolean).join(', ')}`
                        : 'Pickup at the yard'}
                    {order.po_number ? ` · PO ${order.po_number}` : ''}
                </p>
            </div>

            {order.shipments.length > 0 && (
                <section className="grid gap-3 rounded-[10px] border border-border bg-card p-5">
                    <h2 className="text-base font-semibold">Deliveries</h2>
                    {order.shipments.map((s) => (
                        <div
                            key={s.number}
                            className="flex flex-wrap justify-between gap-3 text-sm"
                        >
                            <span className="font-medium">
                                {s.status}
                                {s.truck ? ` · ${s.truck}` : ''}
                            </span>
                            <span className="text-muted-foreground">
                                {s.delivered_at
                                    ? `Signed for by ${s.pod_name} · ${s.delivered_at}`
                                    : s.eta
                                      ? `ETA ${s.eta}`
                                      : 'Being scheduled'}
                            </span>
                        </div>
                    ))}
                </section>
            )}

            <section className="overflow-x-auto rounded-[10px] border border-border bg-card">
                <table className="w-full min-w-[560px] border-collapse text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th className="px-5 py-2.5 font-medium">Item</th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Quantity
                            </th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Your price
                            </th>
                            <th className="px-5 py-2.5 text-right font-medium">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {order.lines.map((l) => (
                            <tr
                                key={l.id}
                                className="border-b border-secondary last:border-0"
                            >
                                <td className="px-5 py-3">{l.description}</td>
                                <td className="figure px-3 py-3 text-right">
                                    {formatQuantity(l.quantity)}{' '}
                                    {unitLabel(l.unit)}
                                </td>
                                <td className="figure px-3 py-3 text-right">
                                    {formatMoney(l.unit_price_cents)}
                                </td>
                                <td className="figure px-5 py-3 text-right">
                                    {formatMoney(l.line_total_cents)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                    <span className="text-sm text-muted-foreground">
                        {order.invoice
                            ? `Invoice ${order.invoice.number}, due ${formatDate(order.invoice.due_on)}`
                            : 'Not invoiced yet'}
                    </span>
                    <span className="figure text-lg font-bold">
                        {formatMoney(order.total_cents)}
                    </span>
                </div>
            </section>
            <div>
                <button
                    type="button"
                    onClick={() =>
                        router.post(PortalController.reorder.url(order.id))
                    }
                    className="h-11 rounded-lg border border-input px-4 font-semibold hover:bg-accent"
                >
                    Order this again
                </button>
            </div>
        </PortalLayout>
    );
}
