import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import OrderActionController from '@/actions/App/Http/Controllers/OrderActionController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import OrderLineController from '@/actions/App/Http/Controllers/OrderLineController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import InputError from '@/components/input-error';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import {
    headerPayload,
    OrderHeaderFields,
    toLocalInput,
} from '@/components/yard/order-header-fields';
import type { OrderHeaderData } from '@/components/yard/order-header-fields';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { OrderStatusBadge, PaymentBadge } from '@/components/yard/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    CreditAssessment,
    Option,
    Order,
    OrderLine,
    OrderProduct,
} from '@/types';

type Props = {
    order: Order;
    credit: CreditAssessment | null;
    products: OrderProduct[];
    tenders: Option[];
    can: { overrideCredit: boolean };
    organizations: { id: number; name: string }[];
    customers: {
        id: number;
        organization_id: number | null;
        first_name: string;
        last_name: string | null;
    }[];
    locations: { id: number; name: string }[];
};

export default function ShowOrder(props: Props) {
    const { order } = props;
    const isDraft = order.status === 'draft';
    const isRental = order.type === 'rental';
    const { errors } = usePage().props as { errors?: Record<string, string> };

    return (
        <>
            <Head title={order.number} />
            <PageBody>
                <PageHeader
                    title={
                        <span className="flex flex-wrap items-center gap-3">
                            {order.number}
                            <OrderStatusBadge
                                status={order.status}
                                rental={isRental}
                            />
                        </span>
                    }
                    description={<OrderSubtitle order={order} />}
                    actions={<HeaderActions order={order} />}
                />

                {errors?.order && (
                    <p
                        role="alert"
                        className="mb-6 rounded-md border border-brick/40 bg-brick/10 px-3 py-2 text-sm text-brick"
                    >
                        {errors.order}
                    </p>
                )}

                <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                    <div className="grid content-start gap-8">
                        <Lines
                            order={order}
                            products={props.products}
                            editable={isDraft}
                        />
                        {isDraft ? (
                            <DraftDetails {...props} />
                        ) : (
                            <OrderDetails order={order} />
                        )}
                    </div>

                    <aside className="grid content-start gap-8">
                        <Totals order={order} />
                        {isDraft && props.credit && (
                            <ConfirmPanel
                                order={order}
                                credit={props.credit}
                                canOverride={props.can.overrideCredit}
                            />
                        )}
                        {!isDraft && order.status !== 'cancelled' && (
                            <PaymentPanel
                                order={order}
                                tenders={props.tenders}
                            />
                        )}
                    </aside>
                </div>
            </PageBody>
        </>
    );
}

ShowOrder.layout = {
    breadcrumbs: [{ title: 'Orders', href: OrderController.index() }],
};

function OrderSubtitle({ order }: { order: Order }) {
    const who = order.organization ? (
        <Link
            href={OrganizationController.show(order.organization.id)}
            className="underline-offset-4 hover:underline"
        >
            {order.organization.name}
        </Link>
    ) : (
        'Walk-in'
    );

    return (
        <>
            {order.type === 'rental' ? 'Rental for ' : 'For '}
            {who}
            {order.customer ? ` (${order.customer.full_name})` : ''}.{' '}
            {order.fulfillment === 'delivery' ? 'Delivery' : 'Pickup'} from{' '}
            {order.location.name}.
        </>
    );
}

function post(url: string, data: Record<string, unknown> = {}) {
    router.post(url, data as never, { preserveScroll: true });
}

function HeaderActions({ order }: { order: Order }) {
    const actions = [];

    if (order.status === 'confirmed') {
        const blocked =
            order.settlement === 'prepaid' && order.balance_cents > 0;
        actions.push(
            <Button
                key="fulfill"
                disabled={blocked}
                title={blocked ? 'Take payment first' : undefined}
                onClick={() =>
                    post(OrderActionController.fulfill.url(order.id))
                }
            >
                {order.type === 'rental' ? 'Send out' : 'Release'}
            </Button>,
        );
    }

    if (order.type === 'rental' && order.status === 'fulfilled') {
        actions.push(
            <Button
                key="return"
                onClick={() =>
                    post(OrderActionController.returnRental.url(order.id))
                }
            >
                Check back in
            </Button>,
        );
    }

    if (order.status === 'draft') {
        actions.push(
            <Button
                key="delete"
                variant="ghost"
                onClick={() => {
                    if (confirm(`Delete draft ${order.number}?`)) {
                        router.delete(OrderController.destroy.url(order.id));
                    }
                }}
            >
                Delete draft
            </Button>,
        );
    }

    if (order.status === 'confirmed' && order.amount_paid_cents === 0) {
        actions.push(
            <Button
                key="cancel"
                variant="ghost"
                onClick={() => {
                    if (confirm(`Cancel ${order.number}?`)) {
                        post(OrderActionController.cancel.url(order.id));
                    }
                }}
            >
                Cancel order
            </Button>,
        );
    }

    return <>{actions}</>;
}

function Lines({
    order,
    products,
    editable,
}: {
    order: Order;
    products: OrderProduct[];
    editable: boolean;
}) {
    const isRental = order.type === 'rental';

    return (
        <Section title={isRental ? 'Equipment' : 'Items'}>
            <Table>
                <thead>
                    <tr>
                        <Th>Item</Th>
                        <Th numeric>Qty</Th>
                        <Th numeric>{isRental ? 'Rate' : 'Price'}</Th>
                        <Th numeric>Amount</Th>
                        {editable && (
                            <Th>
                                <span className="sr-only">Actions</span>
                            </Th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {order.lines.length === 0 && (
                        <EmptyRow colSpan={editable ? 5 : 4}>
                            No items yet. Add the first one below.
                        </EmptyRow>
                    )}
                    {order.lines.map((line) =>
                        editable ? (
                            <EditableLine
                                key={line.id}
                                order={order}
                                line={line}
                            />
                        ) : (
                            <tr key={line.id}>
                                <Td>
                                    <div className="font-medium">
                                        {line.description}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {line.sku}
                                    </div>
                                </Td>
                                <Td numeric>
                                    {formatQuantity(line.quantity)}{' '}
                                    {unitLabel(line.unit)}
                                </Td>
                                <Td numeric>
                                    <Rate line={line} />
                                </Td>
                                <Td numeric>
                                    {formatMoney(line.line_total_cents)}
                                </Td>
                            </tr>
                        ),
                    )}
                </tbody>
            </Table>
            {editable && <AddLine order={order} products={products} />}
        </Section>
    );
}

/**
 * Unit price, plus "× 3 days" on rental lines.
 */
function Rate({ line }: { line: OrderLine }) {
    if (!line.period_unit) {
        return <>{formatMoney(line.unit_price_cents)}</>;
    }

    const unit = line.periods === 1 ? line.period_unit : `${line.period_unit}s`;

    return (
        <>
            {formatMoney(line.unit_price_cents)}
            <span className="block text-xs text-muted-foreground">
                × {line.periods} {unit}
            </span>
        </>
    );
}

function EditableLine({ order, line }: { order: Order; line: OrderLine }) {
    const [quantity, setQuantity] = useState(
        formatQuantity(line.quantity).replace(/,/g, ''),
    );

    function save() {
        if (
            Number(quantity) === Number(line.quantity) ||
            !(Number(quantity) > 0)
        ) {
            return;
        }

        router.patch(
            OrderLineController.update.url({ order: order.id, line: line.id }),
            { quantity },
            { preserveScroll: true },
        );
    }

    return (
        <tr>
            <Td>
                <div className="font-medium">{line.description}</div>
                <div className="text-xs text-muted-foreground">
                    {line.sku}
                    {line.price_overridden && ', price set by hand'}
                </div>
            </Td>
            <Td numeric>
                <div className="flex items-center justify-end gap-1.5">
                    <Input
                        aria-label={`Quantity of ${line.description}`}
                        inputMode="decimal"
                        className="h-8 w-20 text-right tabular-nums"
                        value={quantity}
                        onChange={(e) => setQuantity(e.target.value)}
                        onBlur={save}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                save();
                            }
                        }}
                    />
                    <span className="w-10 text-left text-muted-foreground">
                        {unitLabel(line.unit)}
                    </span>
                </div>
            </Td>
            <Td numeric>
                <Rate line={line} />
            </Td>
            <Td numeric>{formatMoney(line.line_total_cents)}</Td>
            <Td className="text-right">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        router.delete(
                            OrderLineController.destroy.url({
                                order: order.id,
                                line: line.id,
                            }),
                            { preserveScroll: true },
                        )
                    }
                >
                    Remove
                </Button>
            </Td>
        </tr>
    );
}

function AddLine({
    order,
    products,
}: {
    order: Order;
    products: OrderProduct[];
}) {
    const { auth } = usePage().props;
    const form = useForm({ product_id: '', quantity: '', unit_price: '' });
    const [search, setSearch] = useState('');
    const product = products.find((p) => String(p.id) === form.data.product_id);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(OrderLineController.store.url(order.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSearch('');
            },
        });
    }

    return (
        <form
            onSubmit={submit}
            className="mt-3 grid items-start gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-[minmax(0,1fr)_7rem_7rem_auto]"
        >
            <Field
                label="Add item"
                htmlFor="product_search"
                error={form.errors.product_id}
            >
                <Input
                    id="product_search"
                    list="order-products"
                    placeholder="Type a name or SKU"
                    aria-describedby="product-hint"
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        const match = products.find(
                            (p) => `${p.name} (${p.sku})` === e.target.value,
                        );
                        form.setData(
                            'product_id',
                            match ? String(match.id) : '',
                        );
                    }}
                />
                <datalist id="order-products">
                    {products.map((p) => (
                        <option key={p.id} value={`${p.name} (${p.sku})`} />
                    ))}
                </datalist>
                <p id="product-hint" className="text-xs text-muted-foreground">
                    {product
                        ? `${formatMoney(product.price_cents)} / ${
                              order.type === 'rental'
                                  ? product.rental_period
                                  : unitLabel(product.unit)
                          }${product.contract_price ? ' (price list)' : ''}${
                              product.on_hand !== null
                                  ? `. ${formatQuantity(product.on_hand)} on hand`
                                  : ''
                          }${
                              product.deposit_cents
                                  ? `. ${formatMoney(product.deposit_cents)} deposit`
                                  : ''
                          }`
                        : 'Pick from the list as you type.'}
                </p>
            </Field>
            <Field
                label="Quantity"
                htmlFor="quantity"
                error={form.errors.quantity}
            >
                <Input
                    id="quantity"
                    inputMode="decimal"
                    value={form.data.quantity}
                    onChange={(e) => form.setData('quantity', e.target.value)}
                    required
                />
            </Field>
            <Field
                label="Price"
                htmlFor="unit_price"
                error={form.errors.unit_price}
                hint={auth.can.manageBusiness ? 'Optional override' : undefined}
            >
                <Input
                    id="unit_price"
                    inputMode="decimal"
                    placeholder={
                        product ? (product.price_cents / 100).toFixed(2) : ''
                    }
                    value={form.data.unit_price}
                    disabled={!auth.can.manageBusiness}
                    onChange={(e) => form.setData('unit_price', e.target.value)}
                />
            </Field>
            <div className="sm:pt-[1.375rem]">
                <Button
                    type="submit"
                    disabled={form.processing || !form.data.product_id}
                >
                    Add
                </Button>
            </div>
        </form>
    );
}

function Totals({ order }: { order: Order }) {
    const isDraft = order.status === 'draft';
    const rows: [string, string, string?][] = [
        ['Subtotal', formatMoney(order.subtotal_cents)],
        [
            'Tax',
            isDraft && order.tax_cents === 0
                ? 'At confirmation'
                : formatMoney(order.tax_cents),
        ],
    ];

    if (order.deposit_cents > 0) {
        rows.push(['Deposit', formatMoney(order.deposit_cents), 'Refundable']);
    }

    return (
        <Section title="Totals">
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                {rows.map(([label, value, note]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">
                            {label}
                            {note && <span className="text-xs"> ({note})</span>}
                        </dt>
                        <dd className="text-right tabular-nums">{value}</dd>
                    </div>
                ))}
                <dt className="mt-2 border-t border-foreground pt-2 font-display text-lg font-semibold">
                    Total
                </dt>
                <dd className="mt-2 border-t border-foreground pt-2 text-right font-display text-lg font-semibold tabular-nums">
                    {formatMoney(order.amount_due_cents)}
                </dd>
                {!isDraft && order.amount_paid_cents > 0 && (
                    <>
                        <dt className="text-muted-foreground">Paid</dt>
                        <dd className="text-right text-moss tabular-nums">
                            {formatMoney(order.amount_paid_cents)}
                        </dd>
                        <dt className="font-medium">Balance</dt>
                        <dd className="text-right font-medium tabular-nums">
                            {formatMoney(order.balance_cents)}
                        </dd>
                    </>
                )}
            </dl>
        </Section>
    );
}

function ConfirmPanel({
    order,
    credit,
    canOverride,
}: {
    order: Order;
    credit: CreditAssessment;
    canOverride: boolean;
}) {
    const form = useForm({ settlement: '', override_reason: '' });
    const [overriding, setOverriding] = useState(false);
    const hasLines = order.lines.length > 0;
    const accountPossible =
        credit.outcome !== 'no_account' && credit.outcome !== 'rental';

    function confirmAs(settlement: 'account' | 'prepaid') {
        form.transform((data) => ({ ...data, settlement }));
        form.post(OrderActionController.confirm.url(order.id), {
            preserveScroll: true,
        });
    }

    function submitOverride(event: FormEvent) {
        event.preventDefault();
        confirmAs('account');
    }

    return (
        <Section title="Confirm">
            {accountPossible && (
                <div className="mb-4 text-sm">
                    <div className="flex items-baseline justify-between">
                        <span className="text-muted-foreground">
                            Available credit
                        </span>
                        <span className="tabular-nums">
                            {formatMoney(credit.available_cents)}
                        </span>
                    </div>
                    <p
                        className={cn(
                            'mt-2',
                            credit.approved ? 'text-moss' : 'text-brick',
                        )}
                    >
                        {credit.approved
                            ? 'This order fits within the credit limit. Tax is added at confirmation, so check again if it’s close.'
                            : credit.message}
                    </p>
                </div>
            )}
            {!accountPossible && (
                <p className="mb-4 text-sm text-muted-foreground">
                    {credit.message}
                </p>
            )}

            <div className="grid gap-2">
                {accountPossible && (
                    <Button
                        disabled={
                            !hasLines || form.processing || !credit.approved
                        }
                        onClick={() => confirmAs('account')}
                    >
                        Confirm on account
                    </Button>
                )}
                <Button
                    variant={
                        accountPossible && credit.approved
                            ? 'outline'
                            : 'default'
                    }
                    disabled={!hasLines || form.processing}
                    onClick={() => confirmAs('prepaid')}
                >
                    Confirm, payment up front
                </Button>
                {accountPossible &&
                    !credit.approved &&
                    credit.overridable &&
                    canOverride &&
                    !overriding && (
                        <Button
                            variant="ghost"
                            onClick={() => setOverriding(true)}
                        >
                            Override credit limit
                        </Button>
                    )}
            </div>

            {overriding && (
                <form onSubmit={submitOverride} className="mt-4 grid gap-3">
                    <Field
                        label="Reason for override"
                        htmlFor="override_reason"
                        hint="Saved on the order with your name."
                    >
                        <Input
                            id="override_reason"
                            value={form.data.override_reason}
                            onChange={(e) =>
                                form.setData('override_reason', e.target.value)
                            }
                            required
                            autoFocus
                        />
                    </Field>
                    <Button type="submit" disabled={form.processing}>
                        Confirm on account anyway
                    </Button>
                </form>
            )}
            <InputError
                className="mt-2"
                message={form.errors.override_reason}
            />
        </Section>
    );
}

function PaymentPanel({ order, tenders }: { order: Order; tenders: Option[] }) {
    const form = useForm({
        tender: tenders[0]?.value ?? 'cash',
        amount: (order.balance_cents / 100).toFixed(2),
        reference: '',
    });
    const link = order.payments.find(
        (p) => p.tender === 'stripe_checkout' && p.status === 'pending',
    );

    function record(event: FormEvent) {
        event.preventDefault();
        form.post(OrderActionController.recordPayment.url(order.id), {
            preserveScroll: true,
        });
    }

    return (
        <Section title="Payment">
            <p className="mb-4 flex items-center gap-2 text-sm">
                <PaymentBadge
                    status={order.payment_status}
                    settlement={order.settlement}
                />
                {order.settlement === 'account' && order.balance_cents > 0 && (
                    <span className="text-muted-foreground">
                        Invoiced on the organization’s terms.
                    </span>
                )}
                {order.settlement === 'prepaid' && order.balance_cents > 0 && (
                    <span className="text-muted-foreground">
                        Needed before{' '}
                        {order.type === 'rental' ? 'it goes out' : 'release'}.
                    </span>
                )}
            </p>

            {order.balance_cents > 0 && (
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Button
                            variant="outline"
                            onClick={() =>
                                post(
                                    OrderActionController.paymentLink.url(
                                        order.id,
                                    ),
                                )
                            }
                        >
                            {link
                                ? 'New card payment link'
                                : 'Card payment link'}
                        </Button>
                        {link?.checkout_url && (
                            <div className="text-sm">
                                <a
                                    href={link.checkout_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-medium underline underline-offset-4"
                                >
                                    Open payment page
                                </a>
                                <button
                                    type="button"
                                    className="ml-3 text-muted-foreground underline-offset-4 hover:underline"
                                    onClick={() =>
                                        navigator.clipboard.writeText(
                                            link.checkout_url ?? '',
                                        )
                                    }
                                >
                                    Copy link
                                </button>
                                <p className="text-xs text-muted-foreground">
                                    For {formatMoney(link.amount_cents)}. Open
                                    it on the counter tablet or send it to the
                                    customer.
                                </p>
                            </div>
                        )}
                    </div>

                    <form
                        onSubmit={record}
                        className="grid gap-3 border-t border-border pt-4"
                    >
                        <div className="text-sm font-medium">
                            Record a payment
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <Field
                                label="How"
                                htmlFor="tender"
                                error={form.errors.tender}
                            >
                                <NativeSelect
                                    id="tender"
                                    value={form.data.tender}
                                    onChange={(e) =>
                                        form.setData('tender', e.target.value)
                                    }
                                >
                                    {tenders.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Amount"
                                htmlFor="amount"
                                error={form.errors.amount}
                            >
                                <Input
                                    id="amount"
                                    inputMode="decimal"
                                    value={form.data.amount}
                                    onChange={(e) =>
                                        form.setData('amount', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        <Field
                            label="Reference"
                            htmlFor="reference"
                            error={form.errors.reference}
                        >
                            <Input
                                id="reference"
                                value={form.data.reference}
                                placeholder="Cheque or auth number"
                                onChange={(e) =>
                                    form.setData('reference', e.target.value)
                                }
                            />
                        </Field>
                        <Button type="submit" disabled={form.processing}>
                            Record payment
                        </Button>
                    </form>
                </div>
            )}

            {order.payments.some((p) => p.status === 'succeeded') && (
                <ul className="mt-4 grid gap-1 border-t border-border pt-3 text-sm">
                    {order.payments
                        .filter((p) => p.status === 'succeeded')
                        .map((p) => (
                            <li
                                key={p.id}
                                className="flex justify-between gap-3"
                            >
                                <span className="text-muted-foreground">
                                    {tenders.find((t) => t.value === p.tender)
                                        ?.label ?? 'Card (payment link)'}
                                    , {formatDate(p.succeeded_at)}
                                    {p.reference ? `, ${p.reference}` : ''}
                                </span>
                                <span className="tabular-nums">
                                    {formatMoney(p.amount_cents)}
                                </span>
                            </li>
                        ))}
                </ul>
            )}
        </Section>
    );
}

function OrderDetails({ order }: { order: Order }) {
    const address = order.delivery_address;
    const rows: [string, string | null][] = [
        ['PO number', order.po_number],
        [
            'Wanted on',
            order.requested_on ? formatDate(order.requested_on) : null,
        ],
        [
            'Rental',
            order.rental_starts_at
                ? `${formatDate(order.rental_starts_at)} to ${formatDate(order.rental_ends_at)}`
                : null,
        ],
        [
            'Deliver to',
            address
                ? [
                      address.line1,
                      address.city,
                      address.region,
                      address.postal_code,
                  ]
                      .filter(Boolean)
                      .join(', ')
                : null,
        ],
        [
            'Confirmed',
            order.confirmed_at ? formatDate(order.confirmed_at) : null,
        ],
        [
            'Released',
            order.fulfilled_at ? formatDate(order.fulfilled_at) : null,
        ],
        ['Returned', order.returned_at ? formatDate(order.returned_at) : null],
        [
            'Credit override',
            order.credit_override_reason
                ? `${order.credit_override_reason} (${order.credit_override_user?.name ?? 'unknown'})`
                : null,
        ],
        ['Notes', order.notes],
    ];

    return (
        <Section title="Details">
            <dl className="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-1.5 text-sm">
                {rows
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
            </dl>
        </Section>
    );
}

function DraftDetails({ order, organizations, customers, locations }: Props) {
    const isRental = order.type === 'rental';
    const form = useForm<OrderHeaderData>({
        organization_id: order.organization_id
            ? String(order.organization_id)
            : '',
        customer_id: order.customer_id ? String(order.customer_id) : '',
        location_id: String(order.location_id),
        fulfillment: order.fulfillment,
        delivery_address: order.delivery_address ?? {},
        requested_on: order.requested_on?.slice(0, 10) ?? '',
        po_number: order.po_number ?? '',
        rental_starts_at: toLocalInput(order.rental_starts_at),
        rental_ends_at: toLocalInput(order.rental_ends_at),
        notes: order.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => headerPayload(data, isRental));
        form.put(OrderController.update.url(order.id), {
            preserveScroll: true,
        });
    }

    return (
        <Section title="Details">
            <form onSubmit={submit} className="grid gap-4">
                <OrderHeaderFields
                    form={form}
                    isRental={isRental}
                    organizations={organizations}
                    customers={customers}
                    locations={locations}
                />
                <div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing || !form.isDirty}
                    >
                        Save details
                    </Button>
                    {form.isDirty && (
                        <span className="ml-3 text-xs text-muted-foreground">
                            Changing the organization re-prices the items.
                        </span>
                    )}
                </div>
            </form>
        </Section>
    );
}
