import { Head, Link } from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { OrderStatusBadge, PaymentBadge } from '@/components/yard/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, OrderSummary, Organization } from '@/types';

type Props = {
    organization: Organization;
    credit: {
        limit_cents: number;
        used_cents: number;
        available_cents: number;
    };
    orders: OrderSummary[];
    paymentTerms: Option[];
};

export default function ShowOrganization({
    organization,
    credit,
    orders,
    paymentTerms,
}: Props) {
    const terms =
        paymentTerms.find((t) => t.value === organization.payment_terms)
            ?.label ?? organization.payment_terms;
    const usedShare =
        credit.limit_cents > 0
            ? Math.min(100, (credit.used_cents / credit.limit_cents) * 100)
            : 0;

    return (
        <>
            <Head title={organization.name} />
            <PageBody>
                <PageHeader
                    title={organization.name}
                    description={[
                        organization.account_number,
                        organization.phone,
                        organization.email,
                    ]
                        .filter(Boolean)
                        .join(', ')}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={OrganizationController.edit(
                                        organization.id,
                                    )}
                                >
                                    Edit
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href={OrderController.create({
                                        query: {
                                            organization_id: organization.id,
                                        },
                                    })}
                                >
                                    New order
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <div className="grid content-start gap-8">
                        <Section title="Credit">
                            {organization.credit_hold && (
                                <p className="mb-3 rounded-md border border-danger/20 bg-danger-soft px-3 py-2 text-sm text-danger">
                                    On credit hold. New orders must be paid up
                                    front.
                                </p>
                            )}
                            {credit.limit_cents > 0 ? (
                                <>
                                    <div className="flex items-baseline justify-between text-sm">
                                        <span>
                                            {formatMoney(
                                                credit.available_cents,
                                            )}{' '}
                                            available
                                        </span>
                                        <span className="text-muted-foreground">
                                            of {formatMoney(credit.limit_cents)}
                                        </span>
                                    </div>
                                    <div
                                        className="mt-2 h-2 overflow-hidden rounded-sm bg-muted"
                                        role="meter"
                                        aria-label="Credit used"
                                        aria-valuemin={0}
                                        aria-valuemax={credit.limit_cents}
                                        aria-valuenow={credit.used_cents}
                                    >
                                        <div
                                            className={cn(
                                                'h-full',
                                                usedShare >= 90
                                                    ? 'bg-danger'
                                                    : 'bg-foreground',
                                            )}
                                            style={{ width: `${usedShare}%` }}
                                        />
                                    </div>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {formatMoney(credit.used_cents)} owing
                                        on open account orders. Terms: {terms}.
                                    </p>
                                </>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No credit limit, so orders are prepaid.
                                </p>
                            )}
                            <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">
                                    Pricing
                                </dt>
                                <dd>
                                    {organization.price_list?.name ??
                                        'List price'}
                                </dd>
                                <dt className="text-muted-foreground">Tax</dt>
                                <dd>
                                    {organization.tax_exempt
                                        ? `Exempt (${organization.tax_exempt_number})`
                                        : 'Taxable'}
                                </dd>
                            </dl>
                        </Section>

                        <Section
                            title="Contacts"
                            actions={
                                <Link
                                    href={CustomerController.create({
                                        query: {
                                            organization_id: organization.id,
                                        },
                                    })}
                                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                >
                                    Add contact
                                </Link>
                            }
                        >
                            {organization.customers?.length ? (
                                <ul className="grid gap-2 text-sm">
                                    {organization.customers.map((c) => (
                                        <li key={c.id}>
                                            <Link
                                                href={CustomerController.edit(
                                                    c.id,
                                                )}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {c.full_name}
                                            </Link>
                                            <div className="text-muted-foreground">
                                                {[c.phone, c.email]
                                                    .filter(Boolean)
                                                    .join(', ')}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No contacts yet.
                                </p>
                            )}
                        </Section>

                        {organization.notes && (
                            <Section title="Notes">
                                <p className="text-sm whitespace-pre-line">
                                    {organization.notes}
                                </p>
                            </Section>
                        )}
                    </div>

                    <Section title="Orders">
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Order</Th>
                                    <Th>Date</Th>
                                    <Th>Status</Th>
                                    <Th numeric>Total</Th>
                                    <Th numeric>Balance</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.length === 0 && (
                                    <EmptyRow colSpan={5}>
                                        No orders yet.
                                    </EmptyRow>
                                )}
                                {orders.map((order) => (
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
                                        <Td className="whitespace-nowrap text-muted-foreground">
                                            {formatDate(order.created_at)}
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
                                        <Td numeric>
                                            {order.status === 'draft'
                                                ? ''
                                                : formatMoney(
                                                      order.balance_cents,
                                                  )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Section>
                </div>
            </PageBody>
        </>
    );
}

ShowOrganization.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
