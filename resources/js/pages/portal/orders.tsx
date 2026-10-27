import { Head, Link, router } from '@inertiajs/react';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import { OrderLineList } from '@/components/yard/order-line-list';
import { PortalLayout } from '@/components/yard/portal-layout';
import { PortalOrderCards } from '@/components/yard/portal-order-cards';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { statusTone } from './home';
import type { PortalOrder } from './home';

export default function PortalOrders({ orders }: { orders: PortalOrder[] }) {
    return (
        <PortalLayout active="orders">
            <Head title="Orders" />
            <h1 className="text-2xl font-bold sm:text-3xl">Orders</h1>
            <section className="overflow-hidden rounded-[10px] border border-border bg-card md:hidden">
                <PortalOrderCards orders={orders} showTotal />
            </section>
            <section className="hidden overflow-x-auto rounded-[10px] border border-border bg-card md:block">
                <table className="w-full min-w-[720px] border-collapse text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th className="px-5 py-2.5 font-medium">Order</th>
                            <th className="px-3 py-2.5 font-medium">
                                Job site
                            </th>
                            <th className="px-3 py-2.5 font-medium">Items</th>
                            <th className="px-3 py-2.5 font-medium">Status</th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Total
                            </th>
                            <th className="px-5 py-2.5">
                                <span className="sr-only">Reorder</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.length === 0 && (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="px-5 py-8 text-center text-muted-foreground"
                                >
                                    No orders yet.
                                </td>
                            </tr>
                        )}
                        {orders.map((o) => (
                            <tr
                                key={o.id}
                                className="border-b border-secondary align-top last:border-0"
                            >
                                <td className="px-5 py-3">
                                    <Link
                                        href={PortalController.order.url(o.id)}
                                        className="figure font-semibold hover:underline"
                                    >
                                        {o.number}
                                    </Link>
                                    <div className="text-xs text-muted-foreground">
                                        {formatDate(o.created_at)}
                                    </div>
                                </td>
                                <td className="px-3 py-3">{o.site}</td>
                                <td className="px-3 py-3">
                                    <OrderLineList
                                        lines={o.lines}
                                        more={o.more_lines}
                                    />
                                </td>
                                <td className="px-3 py-3">
                                    <span
                                        className={cn(
                                            'rounded-full px-2 py-1 text-xs font-semibold',
                                            statusTone(o.status),
                                        )}
                                    >
                                        {o.status}
                                    </span>
                                </td>
                                <td className="figure px-3 py-3 text-right">
                                    {formatMoney(o.total_cents)}
                                </td>
                                <td className="px-5 py-3 text-right">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                PortalController.reorder.url(
                                                    o.id,
                                                ),
                                            )
                                        }
                                        className="rounded-lg border border-input px-3 py-1.5 font-medium hover:bg-accent"
                                    >
                                        Reorder
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </PortalLayout>
    );
}
