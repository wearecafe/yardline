import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import FulfillmentController from '@/actions/App/Http/Controllers/FulfillmentController';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import OrderActionController from '@/actions/App/Http/Controllers/OrderActionController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import OrderLineController from '@/actions/App/Http/Controllers/OrderLineController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import ProductUnitController from '@/actions/App/Http/Controllers/ProductUnitController';
import QuoteController from '@/actions/App/Http/Controllers/QuoteController';
import { Chip } from '@/components/yard/chip';
import { History } from '@/components/yard/history';
import type { HistoryEvent } from '@/components/yard/history';
import InputError from '@/components/input-error';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import {
    headerPayload,
    OrderHeaderFields,
    toLocalInput,
} from '@/components/yard/order-header-fields';
import type {
    JobSite,
    OrderHeaderData,
} from '@/components/yard/order-header-fields';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { checkFit, RoundingPrompt } from '@/components/yard/rounding-prompt';
import type { FitResult } from '@/components/yard/rounding-prompt';
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
    sites: JobSite[];
    deliveryMethod: 'zone' | 'distance' | 'truckload';
    history: HistoryEvent[];
    quote: {
        is_quote: boolean;
        expired: boolean;
        locked: boolean;
        url: string | null;
    };
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
                        className="mb-6 rounded-md border border-danger/20 bg-danger-soft px-3 py-2 text-sm text-danger"
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
                        {(order.shipments?.length ?? 0) > 0 && (
                            <Shipments order={order} />
                        )}
                        {isDraft ? (
                            <DraftDetails {...props} />
                        ) : (
                            <OrderDetails order={order} />
                        )}
                    </div>

                    <aside className="grid content-start gap-8">
                        <Totals order={order} />
                        {(order.credit_notes?.length ?? 0) > 0 && (
                            <Section title="Credits">
                                <ul className="grid gap-1.5 text-sm">
                                    {order.credit_notes!.map((c) => (
                                        <li
                                            key={c.id}
                                            className="flex justify-between gap-3"
                                        >
                                            <Link
                                                href={ReturnController.show.url(
                                                    c.id,
                                                )}
                                                className="figure hover:underline"
                                            >
                                                {c.number}
                                            </Link>
                                            <span className="figure">
                                                {formatMoney(c.total_cents)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}
                        {isDraft && !isRental && (
                            <QuotePanel order={order} quote={props.quote} />
                        )}
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
                        <Section title="History">
                            <History events={props.history} />
                        </Section>
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

    if (order.status === 'confirmed' && order.type !== 'rental') {
        actions.push(
            <Button
                key="pick"
                variant="outline"
                onClick={() => post(FulfillmentController.start.url(order.id))}
            >
                Start picking
            </Button>,
        );
    }

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
                {order.type === 'rental'
                    ? 'Send out'
                    : order.fulfillment === 'delivery'
                      ? 'Load all'
                      : 'Hand over all'}
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

    if (order.invoice) {
        actions.push(
            <Button key="invoice" variant="ghost" asChild>
                <Link href={InvoiceController.show.url(order.invoice.id)}>
                    Invoice {order.invoice.number}
                </Link>
            </Button>,
        );
    }

    if (
        order.type !== 'rental' &&
        order.lines.some(
            (l) =>
                Number(l.shipped_quantity ?? 0) >
                Number(l.returned_quantity ?? 0),
        )
    ) {
        actions.push(
            <Button key="return" variant="ghost" asChild>
                <Link href={ReturnController.create.url(order.id)}>
                    Take a return
                </Link>
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
    const form = useForm({
        product_id: '',
        product_unit_id: '',
        quantity: '',
        unit_price: '',
        price_reason: '',
    });
    const [search, setSearch] = useState('');
    const [fit, setFit] = useState<FitResult | null>(null);
    const [checking, setChecking] = useState(false);
    const product = products.find((p) => String(p.id) === form.data.product_id);
    const units = product?.units ?? [];
    const unit =
        units.find((u) => String(u.id) === form.data.product_unit_id) ??
        units.find((u) => u.id === product?.default_unit_id) ??
        units.find((u) => u.is_base);
    const price = unit?.price_cents ?? product?.price_cents ?? 0;
    const sourceLabel: Record<string, string> = {
        contract: ' (contract price)',
        price_list: ' (price list)',
        category: ' (category discount)',
        'volume+category': ' (volume price + category discount)',
        exempt: ' (exempt from price lists)',
    };
    const exempt = product?.price_list_exempt ?? false;
    const canSetPrice = auth.can.setPrices && (!exempt || auth.can.priceExempt);

    function post(round: boolean) {
        router.post(
            OrderLineController.store.url(order.id),
            {
                ...form.data,
                product_unit_id: unit ? String(unit.id) : '',
                ...(round
                    ? { round: true, unit_price: '', price_reason: '' }
                    : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
                onError: (errors) => {
                    form.clearErrors();
                    form.setError(errors as Record<string, string>);
                },
                onSuccess: () => {
                    form.reset();
                    form.clearErrors();
                    setSearch('');
                    setFit(null);
                },
            },
        );
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        setFit(null);

        // Products that round are checked first, so staff see and accept
        // the mix of units before it goes on the order.
        if (product && order.type === 'sale' && product.rounding !== 'off') {
            setChecking(true);

            try {
                const result = await checkFit(
                    ProductUnitController.fit.url(product.id),
                    form.data.quantity,
                    unit?.id ?? null,
                );

                if (!result.fits && result.proposal) {
                    form.clearErrors();
                    setFit(result);

                    return;
                }

                if (!result.fits && result.problem) {
                    form.setError('quantity', result.problem);

                    return;
                }
            } catch {
                // Fall through: the server checks again when the line is added.
            } finally {
                setChecking(false);
            }
        }

        post(false);
    }

    return (
        <form
            onSubmit={submit}
            className="mt-3 grid items-start gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-[minmax(0,1fr)_7rem_9rem_7rem_auto]"
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
                        setFit(null);
                        form.setData((d) => ({
                            ...d,
                            product_id: match ? String(match.id) : '',
                            product_unit_id: '',
                        }));
                    }}
                />
                <datalist id="order-products">
                    {products.map((p) => (
                        <option key={p.id} value={`${p.name} (${p.sku})`} />
                    ))}
                </datalist>
                <p id="product-hint" className="text-xs text-muted-foreground">
                    {product
                        ? `${formatMoney(price)} / ${
                              order.type === 'rental'
                                  ? product.rental_period
                                  : unit && !unit.is_base
                                    ? unit.name.toLowerCase()
                                    : unitLabel(product.unit)
                          }${sourceLabel[unit?.price_source ?? ''] ?? ''}${
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
                    onChange={(e) => {
                        setFit(null);
                        form.setData('quantity', e.target.value);
                    }}
                    required
                />
            </Field>
            <Field
                label="Unit"
                htmlFor="product_unit_id"
                error={form.errors.product_unit_id}
            >
                <NativeSelect
                    id="product_unit_id"
                    value={form.data.product_unit_id || String(unit?.id ?? '')}
                    disabled={units.length < 2}
                    onChange={(e) => {
                        setFit(null);
                        form.setData('product_unit_id', e.target.value);
                    }}
                >
                    {units.length === 0 && <option value="">—</option>}
                    {units.map((u) => (
                        <option key={u.id} value={u.id}>
                            {u.sellable ? u.name : `${u.name} (rounds)`}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field
                label="Price"
                htmlFor="unit_price"
                error={form.errors.unit_price}
                hint={
                    exempt && !canSetPrice
                        ? 'Exempt from discounts'
                        : canSetPrice
                          ? 'Optional override'
                          : undefined
                }
            >
                <Input
                    id="unit_price"
                    inputMode="decimal"
                    placeholder={product ? (price / 100).toFixed(2) : ''}
                    value={form.data.unit_price}
                    disabled={!canSetPrice}
                    onChange={(e) => form.setData('unit_price', e.target.value)}
                />
            </Field>
            <div className="sm:pt-[1.375rem]">
                <Button
                    type="submit"
                    disabled={
                        form.processing || checking || !form.data.product_id
                    }
                >
                    Add
                </Button>
            </div>
            {exempt && canSetPrice && form.data.unit_price !== '' && (
                <div className="sm:col-span-5">
                    <Field
                        label="Reason for changing an exempt product's price"
                        htmlFor="price_reason"
                        error={form.errors.price_reason}
                        hint="Goes in the audit log."
                    >
                        <Input
                            id="price_reason"
                            value={form.data.price_reason}
                            onChange={(e) =>
                                form.setData('price_reason', e.target.value)
                            }
                            required
                        />
                    </Field>
                </div>
            )}
            {fit && (
                <div className="sm:col-span-5">
                    <RoundingPrompt
                        fit={fit}
                        busy={checking}
                        onAccept={() => post(true)}
                        onCancel={() => {
                            setFit(null);
                            document.getElementById('quantity')?.focus();
                        }}
                    />
                </div>
            )}
        </form>
    );
}

function Totals({ order }: { order: Order }) {
    const isDraft = order.status === 'draft';
    const palletFees = order.pallet_fee_cents ?? 0;
    const showDelivery =
        order.delivery_fee_cents > 0 || order.fulfillment === 'delivery';
    const feePallets = order.lines.reduce(
        (sum, l) => sum + (l.fee_pallets ?? 0),
        0,
    );
    const rows: [string, string, string?][] = [
        ...(showDelivery || palletFees > 0
            ? ([
                  [
                      'Items',
                      formatMoney(
                          order.subtotal_cents -
                              order.delivery_fee_cents -
                              palletFees,
                      ),
                  ],
              ] as [string, string, string?][])
            : []),
        ...(palletFees > 0
            ? ([
                  [
                      'Pallet usage fees',
                      formatMoney(palletFees),
                      `${feePallets} ${feePallets === 1 ? 'pallet' : 'pallets'}, not refundable`,
                  ],
              ] as [string, string, string?][])
            : []),
        ...(showDelivery
            ? ([
                  [
                      'Delivery',
                      formatMoney(order.delivery_fee_cents),
                      order.delivery_zone?.name ??
                          (order.delivery_loads
                              ? `${order.delivery_loads} ${order.delivery_loads === 1 ? 'load' : 'loads'}`
                              : undefined),
                  ],
              ] as [string, string, string?][])
            : []),
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
                <dt className="mt-2 border-t border-foreground pt-2 text-lg font-semibold">
                    Total
                </dt>
                <dd className="mt-2 border-t border-foreground pt-2 text-right text-lg font-semibold tabular-nums">
                    {formatMoney(order.amount_due_cents)}
                </dd>
                {!isDraft && order.amount_paid_cents > 0 && (
                    <>
                        <dt className="text-muted-foreground">Paid</dt>
                        <dd className="text-right text-success tabular-nums">
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
                            credit.approved ? 'text-success' : 'text-danger',
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

function DraftDetails({
    order,
    organizations,
    customers,
    locations,
    sites,
    deliveryMethod,
}: Props) {
    const isRental = order.type === 'rental';
    const form = useForm<OrderHeaderData>({
        organization_id: order.organization_id
            ? String(order.organization_id)
            : '',
        customer_id: order.customer_id ? String(order.customer_id) : '',
        organization_location_id: order.organization_location_id
            ? String(order.organization_location_id)
            : '',
        location_id: String(order.location_id),
        fulfillment: order.fulfillment,
        delivery_address: order.delivery_address ?? {},
        requested_on: order.requested_on?.slice(0, 10) ?? '',
        po_number: order.po_number ?? '',
        rental_starts_at: toLocalInput(order.rental_starts_at),
        rental_ends_at: toLocalInput(order.rental_ends_at),
        notes: order.notes ?? '',
        delivery_km: order.delivery_km ? String(Number(order.delivery_km)) : '',
        delivery_fee: order.delivery_fee_manual
            ? (order.delivery_fee_cents / 100).toFixed(2)
            : '',
        needs_boom: order.needs_boom,
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
                    sites={sites}
                />
                {form.data.fulfillment === 'delivery' && !isRental && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        {deliveryMethod === 'distance' && (
                            <Field
                                label="Distance from the yard (km)"
                                htmlFor="delivery_km"
                                error={form.errors.delivery_km}
                            >
                                <Input
                                    id="delivery_km"
                                    inputMode="decimal"
                                    value={form.data.delivery_km ?? ''}
                                    onChange={(e) =>
                                        form.setData(
                                            'delivery_km',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        )}
                        <Field
                            label="Delivery fee"
                            htmlFor="delivery_fee"
                            error={form.errors.delivery_fee}
                            hint={
                                order.delivery_fee_manual
                                    ? 'Set by hand. Clear it to use the yard’s pricing.'
                                    : `Automatic: ${formatMoney(order.delivery_fee_cents)}${order.delivery_zone ? ` (${order.delivery_zone.name})` : ''}${order.delivery_loads ? ` (${order.delivery_loads} ${order.delivery_loads === 1 ? 'load' : 'loads'})` : ''}`
                            }
                        >
                            <Input
                                id="delivery_fee"
                                inputMode="decimal"
                                placeholder={(
                                    order.delivery_fee_cents / 100
                                ).toFixed(2)}
                                value={form.data.delivery_fee ?? ''}
                                onChange={(e) =>
                                    form.setData('delivery_fee', e.target.value)
                                }
                            />
                        </Field>
                        <div className="sm:pt-7">
                            <Checkbox
                                id="needs_boom"
                                label="Needs a boom truck"
                                checked={Boolean(form.data.needs_boom)}
                                onChange={(v) => form.setData('needs_boom', v)}
                            />
                        </div>
                    </div>
                )}
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

/**
 * Send the draft as a quote, with prices held until it expires. Sending
 * again after changes makes a new revision.
 */
function QuotePanel({ order, quote }: { order: Order; quote: Props['quote'] }) {
    const inThirty = new Date(Date.now() + 30 * 86_400_000)
        .toISOString()
        .slice(0, 10);
    const form = useForm({
        expires_on: order.quote_expires_on?.slice(0, 10) ?? inThirty,
        email: Boolean(order.customer?.email),
    });

    return (
        <Section
            title={
                quote.is_quote ? `Quote · revision ${order.revision}` : 'Quote'
            }
            actions={
                quote.is_quote ? (
                    quote.expired ? (
                        <Chip tone="danger">Expired</Chip>
                    ) : order.quote_accepted_at ? (
                        <Chip tone="success">Accepted</Chip>
                    ) : (
                        <Chip tone="info">Sent</Chip>
                    )
                ) : undefined
            }
        >
            {quote.is_quote ? (
                <div className="grid gap-1 text-sm">
                    <p>
                        {quote.expired
                            ? `Expired ${formatDate(order.quote_expires_on)}. Prices update to today's on the next change.`
                            : `Prices held until ${formatDate(order.quote_expires_on)}.`}
                    </p>
                    {order.quote_accepted_at && (
                        <p className="text-muted-foreground">
                            Accepted by {order.quote_accepted_by} on{' '}
                            {formatDate(order.quote_accepted_at)}. Confirm it to
                            make it an order.
                        </p>
                    )}
                    {quote.url && (
                        <p>
                            <a
                                href={quote.url}
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium underline underline-offset-4"
                            >
                                Customer's quote page
                            </a>
                        </p>
                    )}
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Send this draft to the customer as a quote. Prices hold
                    until it expires, and they can accept it online.
                </p>
            )}
            <form
                className="grid gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(QuoteController.send.url(order.id), {
                        preserveScroll: true,
                    });
                }}
            >
                <Field
                    label="Good until"
                    htmlFor="quote_expires_on"
                    error={form.errors.expires_on}
                >
                    <Input
                        id="quote_expires_on"
                        type="date"
                        value={form.data.expires_on}
                        onChange={(e) =>
                            form.setData('expires_on', e.target.value)
                        }
                    />
                </Field>
                <Checkbox
                    id="quote_email"
                    label={
                        order.customer?.email
                            ? `Email it to ${order.customer.email}`
                            : 'No email on file for the contact'
                    }
                    checked={form.data.email}
                    disabled={!order.customer?.email}
                    onChange={(checked) => form.setData('email', checked)}
                />
                <Button
                    type="submit"
                    variant="outline"
                    disabled={form.processing || order.lines.length === 0}
                >
                    {quote.is_quote ? 'Send revised quote' : 'Send quote'}
                </Button>
            </form>
            {(order.revisions?.length ?? 0) > 0 && (
                <ul className="grid gap-1.5 border-t border-border pt-3 text-sm">
                    {order.revisions!.map((r) => (
                        <li key={r.id} className="flex justify-between gap-3">
                            <span>
                                Revision {r.revision} ·{' '}
                                {formatDate(r.created_at)}
                                {r.creator ? ` · ${r.creator.name}` : ''}
                            </span>
                            <span className="figure">
                                {formatMoney(r.snapshot.total_cents)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

const shipmentStatus: Record<
    string,
    [string, 'success' | 'info' | 'warning' | 'neutral']
> = {
    picking: ['Picking', 'warning'],
    picked: ['Picked', 'warning'],
    loaded: ['Loaded', 'info'],
    ready: ['Ready for pickup', 'info'],
    out: ['Out for delivery', 'info'],
    delivered: ['Delivered', 'success'],
    picked_up: ['Picked up', 'success'],
    cancelled: ['Cancelled', 'neutral'],
};

/**
 * Each load of the order: what was picked, where it is, and who signed.
 */
function Shipments({ order }: { order: Order }) {
    return (
        <Section
            title="Shipments"
            description={
                order.fulfillment_status === 'partly_shipped'
                    ? 'Part of this order has shipped. Start picking again for the rest.'
                    : undefined
            }
        >
            <ul className="grid gap-3 text-sm">
                {order.shipments!.map((s) => {
                    const [label, tone] = shipmentStatus[s.status] ?? [
                        s.status,
                        'neutral',
                    ];
                    const shorts = s.lines.filter((l) => l.short).length;

                    return (
                        <li
                            key={s.id}
                            className="flex flex-wrap items-start justify-between gap-3 border-b border-secondary pb-3 last:border-0 last:pb-0"
                        >
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="figure font-medium">
                                        {s.number}
                                    </span>
                                    <Chip tone={tone}>{label}</Chip>
                                    {shorts > 0 && (
                                        <Chip tone="warning">
                                            {shorts} short
                                        </Chip>
                                    )}
                                    {s.check_stage && (
                                        <Chip tone="warning">
                                            Awaiting check at{' '}
                                            {s.check_stage === 'loaded'
                                                ? 'Loaded'
                                                : 'Picked'}
                                        </Chip>
                                    )}
                                </div>
                                <div className="mt-0.5 text-muted-foreground">
                                    {[
                                        `${s.lines.length} ${s.lines.length === 1 ? 'item' : 'items'}`,
                                        s.weight_kg
                                            ? `≈ ${(Number(s.weight_kg) / 1000).toFixed(1)} t`
                                            : null,
                                        s.picker?.name
                                            ? `picked by ${s.picker.name}`
                                            : null,
                                        s.stop?.truck
                                            ? `${s.stop.truck.name}${s.stop.date ? `, ${formatDate(s.stop.date)}` : ''}${s.stop.starts_at ? ` ${s.stop.starts_at.slice(0, 5)}` : ''}`
                                            : null,
                                        s.pod_name
                                            ? `signed by ${s.pod_name} ${formatDate(s.delivered_at)}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                                {s.checks && s.checks.length > 0 && (
                                    <ul className="mt-1 grid gap-0.5 text-muted-foreground">
                                        {[...s.checks].reverse().map((c) => (
                                            <li key={c.id}>
                                                {c.stage === 'loaded'
                                                    ? 'Loaded'
                                                    : 'Picked'}{' '}
                                                {c.outcome === 'signed_off'
                                                    ? 'checked'
                                                    : 'sent back'}{' '}
                                                by {c.user?.name ?? 'someone'}{' '}
                                                {formatDate(c.created_at)}
                                                {c.reason
                                                    ? `: ${c.reason}`
                                                    : ''}
                                                {c.notes ? ` · ${c.notes}` : ''}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            <div className="flex gap-2">
                                {['picking', 'picked'].includes(s.status) && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={FulfillmentController.pick.url(
                                                s.id,
                                            )}
                                        >
                                            Picker
                                        </Link>
                                    </Button>
                                )}
                                {[
                                    'loaded',
                                    'out',
                                    'ready',
                                    'delivered',
                                    'picked_up',
                                ].includes(s.status) &&
                                    !s.check_stage && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={DeliveryController.show.url(
                                                    s.id,
                                                )}
                                            >
                                                {s.status === 'delivered' ||
                                                s.status === 'picked_up'
                                                    ? 'Proof'
                                                    : s.status === 'ready'
                                                      ? 'Hand over'
                                                      : 'Deliver'}
                                            </Link>
                                        </Button>
                                    )}
                                <Button variant="ghost" size="sm" asChild>
                                    <a
                                        href={FulfillmentController.ticket.url(
                                            s.id,
                                        )}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Ticket
                                    </a>
                                </Button>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}
