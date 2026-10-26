import { Head, Link } from '@inertiajs/react';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import ReceivablesController from '@/actions/App/Http/Controllers/ReceivablesController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { History } from '@/components/yard/history';
import type { HistoryEvent } from '@/components/yard/history';
import {
    ContactsSection,
    ContractPricesSection,
    SitesSection,
} from '@/components/yard/organization-sections';
import type {
    OrgContact,
    OrgContractPrice,
    OrgSite,
} from '@/components/yard/organization-sections';
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
    account: {
        pallets_out: number;
        credit_cents: number;
        overdue_cents: number;
    };
    history: HistoryEvent[];
    contacts: OrgContact[];
    roles: Option[];
    otherCustomers: { id: number; name: string }[];
    sites: OrgSite[];
    yards: { id: number; name: string }[];
    contractPrices: OrgContractPrice[];
    products: {
        id: number;
        label: string;
        units: { id: number; name: string; is_base: boolean }[];
    }[];
};

export default function ShowOrganization(props: Props) {
    const { organization, credit, orders, paymentTerms } = props;
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
                                    href={ReceivablesController.statement(
                                        organization.id,
                                    )}
                                >
                                    Statement
                                </Link>
                            </Button>
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
                            {(props.account.overdue_cents > 0 ||
                                props.account.credit_cents > 0 ||
                                props.account.pallets_out > 0) && (
                                <ul className="mb-3 grid gap-1 text-sm">
                                    {props.account.overdue_cents > 0 && (
                                        <li className="font-semibold text-danger">
                                            {formatMoney(
                                                props.account.overdue_cents,
                                            )}{' '}
                                            overdue
                                        </li>
                                    )}
                                    {props.account.credit_cents > 0 && (
                                        <li className="text-success">
                                            {formatMoney(
                                                props.account.credit_cents,
                                            )}{' '}
                                            credit to use
                                        </li>
                                    )}
                                    {props.account.pallets_out > 0 && (
                                        <li>
                                            {props.account.pallets_out} pallets
                                            out on deposit
                                        </li>
                                    )}
                                </ul>
                            )}
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

                        <ContactsSection
                            organizationId={organization.id}
                            contacts={props.contacts}
                            roles={props.roles}
                            otherCustomers={props.otherCustomers}
                        />

                        {organization.notes && (
                            <Section title="Notes">
                                <p className="text-sm whitespace-pre-line">
                                    {organization.notes}
                                </p>
                            </Section>
                        )}
                    </div>

                    <div className="grid content-start gap-8">
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
                                                        status={
                                                            order.is_quote
                                                                ? 'quote'
                                                                : order.status
                                                        }
                                                        rental={
                                                            order.type ===
                                                            'rental'
                                                        }
                                                    />
                                                    {order.status !==
                                                        'draft' && (
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
                        <SitesSection
                            organizationId={organization.id}
                            sites={props.sites}
                            yards={props.yards}
                        />
                        <ContractPricesSection
                            organizationId={organization.id}
                            prices={props.contractPrices}
                            products={props.products}
                        />
                        <Section title="History">
                            <History events={props.history} />
                        </Section>
                    </div>
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
