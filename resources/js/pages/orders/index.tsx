import { Head, Link, router } from '@inertiajs/react';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { SearchBox } from '@/components/yard/search-box';
import { OrderStatusBadge, PaymentBadge } from '@/components/yard/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import type { OrderSummary, Paginated } from '@/types';

type Filters = { search?: string; status?: string; type?: string };

export default function Orders({
    orders,
    filters,
}: {
    orders: Paginated<OrderSummary>;
    filters: Filters;
}) {
    function filter(changes: Filters) {
        router.get(
            OrderController.index.url(),
            { ...filters, ...changes },
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Orders" />
            <PageBody>
                <PageHeader
                    title="Orders"
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

                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <SearchBox
                        value={filters.search}
                        placeholder="Order number, PO or organization"
                        extra={{ status: filters.status, type: filters.type }}
                    />
                    <NativeSelect
                        aria-label="Status"
                        className="w-auto"
                        value={filters.status ?? ''}
                        onChange={(e) =>
                            filter({ status: e.target.value || undefined })
                        }
                    >
                        <option value="">Any status</option>
                        <option value="draft">Draft</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="fulfilled">Released</option>
                        <option value="returned">Returned</option>
                        <option value="cancelled">Cancelled</option>
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Type"
                        className="w-auto"
                        value={filters.type ?? ''}
                        onChange={(e) =>
                            filter({ type: e.target.value || undefined })
                        }
                    >
                        <option value="">Sales and rentals</option>
                        <option value="sale">Sales</option>
                        <option value="rental">Rentals</option>
                    </NativeSelect>
                </div>

                <Table>
                    <thead>
                        <tr>
                            <Th>Order</Th>
                            <Th>For</Th>
                            <Th>Date</Th>
                            <Th>Status</Th>
                            <Th numeric>Total</Th>
                            <Th numeric>Balance</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.data.length === 0 && (
                            <EmptyRow colSpan={6}>No orders match.</EmptyRow>
                        )}
                        {orders.data.map((order) => (
                            <tr key={order.id} className="hover:bg-accent/50">
                                <Td>
                                    <Link
                                        href={OrderController.show(order.id)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {order.number}
                                    </Link>
                                </Td>
                                <Td className="max-w-56 truncate">
                                    {order.organization ?? order.customer ?? (
                                        <span className="text-muted-foreground">
                                            Walk-in
                                        </span>
                                    )}
                                </Td>
                                <Td className="whitespace-nowrap text-muted-foreground">
                                    {formatDate(order.created_at)}
                                </Td>
                                <Td>
                                    <div className="flex items-center gap-2">
                                        <OrderStatusBadge
                                            status={order.status}
                                            rental={order.type === 'rental'}
                                        />
                                        {order.status !== 'draft' &&
                                            order.status !== 'cancelled' && (
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
                                <Td numeric>
                                    {order.status === 'draft' ||
                                    order.status === 'cancelled'
                                        ? ''
                                        : formatMoney(order.balance_cents)}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
                <Pagination page={orders} />
            </PageBody>
        </>
    );
}

Orders.layout = {
    breadcrumbs: [{ title: 'Orders', href: OrderController.index() }],
};
