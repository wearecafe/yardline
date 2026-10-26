import { Head, Link } from '@inertiajs/react';
import InventoryController from '@/actions/App/Http/Controllers/InventoryController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import { Table, Td, Th, EmptyRow } from '@/components/yard/data-table';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { OrderStatusBadge, PaymentBadge } from '@/components/yard/status-badge';
import { Button } from '@/components/ui/button';
import { formatMoney, formatQuantity, unitLabel } from '@/lib/format';
import { dashboard } from '@/routes';
import type { OrderSummary } from '@/types';

type Props = {
    stats: {
        drafts: number;
        to_release: number;
        rentals_out: number;
        sales_today_cents: number;
    };
    recentOrders: OrderSummary[];
    lowStock: {
        id: number;
        product: { id: number; sku: string; name: string; unit: string };
        location: string;
        on_hand: string;
        reorder_point: string;
    }[];
};

export default function Dashboard({ stats, recentOrders, lowStock }: Props) {
    const today = new Date().toLocaleDateString('en-CA', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });

    return (
        <>
            <Head title="Today" />
            <PageBody>
                <PageHeader
                    title={today}
                    description={
                        <>
                            {formatMoney(stats.sales_today_cents)} confirmed
                            today. {stats.to_release} to release, {stats.drafts}{' '}
                            in draft, {stats.rentals_out}{' '}
                            {stats.rentals_out === 1 ? 'rental' : 'rentals'}{' '}
                            out.
                        </>
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={OrderController.create({
                                        query: { type: 'rental' },
                                    })}
                                >
                                    New rental
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={OrderController.create()}>
                                    New order
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Section
                        title="Latest orders"
                        actions={
                            <Link
                                href={OrderController.index()}
                                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                            >
                                All orders
                            </Link>
                        }
                    >
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Order</Th>
                                    <Th>For</Th>
                                    <Th>Status</Th>
                                    <Th numeric>Total</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentOrders.length === 0 && (
                                    <EmptyRow colSpan={4}>
                                        No orders yet. Start one with New order.
                                    </EmptyRow>
                                )}
                                {recentOrders.map((order) => (
                                    <tr
                                        key={order.id}
                                        className="hover:bg-accent/50"
                                    >
                                        <Td>
                                            <Link
                                                href={OrderController.show(
                                                    order.id,
                                                )}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {order.number}
                                            </Link>
                                        </Td>
                                        <Td className="max-w-48 truncate">
                                            {order.organization ??
                                                order.customer ??
                                                'Walk-in'}
                                        </Td>
                                        <Td>
                                            <div className="flex items-center gap-2">
                                                <OrderStatusBadge
                                                    status={order.status}
                                                    rental={
                                                        order.type === 'rental'
                                                    }
                                                />
                                                {order.status !== 'draft' && (
                                                    <PaymentBadge
                                                        status={
                                                            order.payment_status
                                                        }
                                                        settlement={
                                                            order.settlement
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </Td>
                                        <Td numeric>
                                            {formatMoney(order.total_cents)}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Section>

                    <Section
                        title="Running low"
                        actions={
                            <Link
                                href={InventoryController.index()}
                                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                            >
                                Inventory
                            </Link>
                        }
                    >
                        {lowStock.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing is at or below its reorder point. Set
                                reorder points on the inventory page to see
                                items here.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border rounded-md border border-border bg-card">
                                {lowStock.map((level) => (
                                    <li
                                        key={level.id}
                                        className="flex items-baseline justify-between gap-3 px-3 py-2 text-sm"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">
                                                {level.product.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {level.location}, reorder at{' '}
                                                {formatQuantity(
                                                    level.reorder_point,
                                                )}
                                            </div>
                                        </div>
                                        <span className="whitespace-nowrap text-brick tabular-nums">
                                            {formatQuantity(level.on_hand)}{' '}
                                            {unitLabel(level.product.unit)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                </div>
            </PageBody>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Today', href: dashboard() }],
};
