import { Head, Link, router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import FulfillmentController from '@/actions/App/Http/Controllers/FulfillmentController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import { Chip } from '@/components/yard/chip';
import { NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { formatMoney, formatQuantity } from '@/lib/format';
import { cn } from '@/lib/utils';

type Card = {
    key: string;
    order_id: number;
    shipment_id: number | null;
    shipment_number: string | null;
    number: string;
    source: string;
    time: string;
    customer: string;
    summary: string;
    mode: string;
    status: string;
    partial: boolean;
    picker: string | null;
};

type PickItem = {
    id: number | null;
    order_line_id: number;
    item: string;
    sku: string | null;
    where: string | null;
    quantity: string;
    unit: string;
    weighed: boolean;
    picked: boolean;
    short: boolean;
};

type Detail = Card & {
    items: PickItem[];
    weight_kg: number;
    deposits: { count: number; cents: number; label: string } | null;
    yard: string;
    price_tier: string | null;
    delivery_address: { line1?: string | null; city?: string | null } | null;
    site_notes: string | null;
    zone: string | null;
    delivery_fee_cents: number;
    payment_status: string;
    settlement: string | null;
    balance_cents: number;
    picker_id: number | null;
};

type Props = {
    columns: { key: string; title: string; cards: Card[] }[];
    detail: Detail | null;
    selected: string | null;
    pickers: { id: number; name: string }[];
    today: string;
};

const time = (iso: string) =>
    new Date(iso)
        .toLocaleTimeString('en-CA', { hour: 'numeric', minute: '2-digit' })
        .replace(/\s?[ap]\.?m\.?/i, '');

function select(key: string) {
    router.get(
        FulfillmentController.queue.url(),
        { card: key },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['detail', 'selected'],
        },
    );
}

/**
 * Orders and picking (yard mockup 3): every confirmed order lands here as
 * a pick list, and moves across as it's picked and loaded.
 */
export default function Queue({
    columns,
    detail,
    selected,
    pickers,
    today,
}: Props) {
    const total = columns.reduce((n, c) => n + c.cards.length, 0);

    return (
        <>
            <Head title="Orders and picking" />
            <PageBody>
                <PageHeader
                    title="Orders and picking"
                    description={`Every confirmed order lands here as a pick list. Today, ${today}.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={OrderController.index()}>
                                All orders
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-start gap-5">
                    <div className="grid min-w-0 flex-[999_1_640px] grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
                        {columns.map((col) => (
                            <section
                                key={col.key}
                                className="flex min-w-0 flex-col gap-2.5 rounded-[10px] bg-secondary p-2.5"
                            >
                                <h2 className="flex items-center justify-between px-1 text-sm font-semibold">
                                    {col.title}
                                    <span className="figure rounded-full bg-card px-2 py-0.5 text-xs">
                                        {col.cards.length}
                                    </span>
                                </h2>
                                {col.cards.length === 0 && (
                                    <p className="px-1 py-3 text-xs text-muted-foreground">
                                        Nothing here.
                                    </p>
                                )}
                                {col.cards.map((card) => (
                                    <button
                                        key={card.key}
                                        type="button"
                                        onClick={() => select(card.key)}
                                        aria-pressed={selected === card.key}
                                        className={cn(
                                            'grid gap-1 rounded-lg border bg-card p-3 text-left transition-colors',
                                            selected === card.key
                                                ? 'border-2 border-primary'
                                                : 'border-border hover:border-[#a8a29e]',
                                        )}
                                    >
                                        <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                            <span className="figure font-semibold text-foreground">
                                                {card.number}
                                            </span>
                                            <span className="figure">
                                                {time(card.time)}
                                            </span>
                                        </div>
                                        <div className="text-sm font-semibold">
                                            {card.customer}
                                        </div>
                                        <div className="text-[13px] text-subtle">
                                            {card.summary}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                            {card.mode}
                                            {card.partial && (
                                                <Chip tone="warning">
                                                    Part shipped
                                                </Chip>
                                            )}
                                            {card.picker && (
                                                <span>· {card.picker}</span>
                                            )}
                                        </div>
                                    </button>
                                ))}
                            </section>
                        ))}
                        {total === 0 && (
                            <p className="col-span-full text-sm text-muted-foreground">
                                No confirmed orders waiting. Confirmed orders
                                show up here to pick.
                            </p>
                        )}
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_320px] flex-col gap-4 rounded-[10px] border border-border bg-card p-5 lg:sticky lg:top-4">
                        {detail ? (
                            <PickPanel detail={detail} pickers={pickers} />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Pick an order to see its pick list.
                            </p>
                        )}
                    </aside>
                </div>
            </PageBody>
        </>
    );
}

function PickPanel({
    detail,
    pickers,
}: {
    detail: Detail;
    pickers: Props['pickers'];
}) {
    const isNew = detail.shipment_id === null;

    return (
        <>
            <div>
                <div className="text-xs text-muted-foreground">
                    <Link
                        href={OrderController.show(detail.order_id)}
                        className="figure hover:underline"
                    >
                        {detail.number}
                    </Link>
                    {detail.shipment_number && ` · ${detail.shipment_number}`} ·{' '}
                    {detail.yard}
                </div>
                <h2 className="text-xl font-semibold">{detail.customer}</h2>
                <p className="text-sm text-muted-foreground">
                    {[
                        detail.price_tier ? `${detail.price_tier} tier` : null,
                        detail.delivery_address?.line1
                            ? `delivery to ${[detail.delivery_address.line1, detail.delivery_address.city].filter(Boolean).join(', ')}`
                            : 'pickup',
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
                {detail.site_notes && (
                    <p className="mt-1 text-sm text-subtle italic">
                        {detail.site_notes}
                    </p>
                )}
            </div>

            <div>
                <h3 className="mb-2 text-sm font-semibold">Pick list</h3>
                <ul className="grid gap-2.5">
                    {detail.items.map((item) => (
                        <li
                            key={item.order_line_id}
                            className="flex items-start justify-between gap-3 border-b border-secondary pb-2.5 text-sm last:border-0"
                        >
                            <div>
                                <div
                                    className={cn(
                                        'font-medium',
                                        item.picked &&
                                            'text-muted-foreground line-through',
                                    )}
                                >
                                    {item.item}
                                </div>
                                {item.where && (
                                    <div className="text-xs text-muted-foreground">
                                        {item.where}
                                    </div>
                                )}
                            </div>
                            <div className="figure text-right font-semibold whitespace-nowrap">
                                {formatQuantity(item.quantity)} {item.unit}
                                {item.short && (
                                    <div>
                                        <Chip tone="warning">Short</Chip>
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>

            <dl className="grid gap-1.5 border-t border-border pt-3 text-sm">
                <div className="flex justify-between">
                    <dt className="text-muted-foreground">Load weight</dt>
                    <dd className="figure">
                        {detail.weight_kg > 0
                            ? `≈ ${(detail.weight_kg / 1000).toFixed(1)} t`
                            : '—'}
                    </dd>
                </div>
                {detail.deposits && (
                    <div className="flex justify-between">
                        <dt className="text-muted-foreground">Deposits</dt>
                        <dd className="figure">{detail.deposits.label}</dd>
                    </div>
                )}
                {detail.zone !== null || detail.delivery_fee_cents > 0 ? (
                    <div className="flex justify-between">
                        <dt className="text-muted-foreground">Delivery</dt>
                        <dd>
                            {[
                                detail.zone,
                                detail.delivery_fee_cents
                                    ? formatMoney(detail.delivery_fee_cents)
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </dd>
                    </div>
                ) : null}
                <div className="flex justify-between">
                    <dt className="text-muted-foreground">Payment</dt>
                    <dd>
                        {detail.balance_cents > 0
                            ? detail.settlement === 'account'
                                ? 'On account'
                                : `${formatMoney(detail.balance_cents)} to take`
                            : 'Paid'}
                    </dd>
                </div>
            </dl>

            {isNew ? (
                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const picker = new FormData(e.currentTarget).get(
                            'picker_id',
                        );
                        router.post(
                            FulfillmentController.start.url(detail.order_id),
                            { picker_id: picker || null },
                        );
                    }}
                >
                    <NativeSelect
                        name="picker_id"
                        aria-label="Picker"
                        defaultValue=""
                    >
                        <option value="">Assign picker later</option>
                        {pickers.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button type="submit">Start picking</Button>
                </form>
            ) : (
                <div className="grid gap-2">
                    {detail.status === 'picking' && (
                        <>
                            <NativeSelect
                                aria-label="Picker"
                                value={
                                    detail.picker_id
                                        ? String(detail.picker_id)
                                        : ''
                                }
                                onChange={(e) =>
                                    router.patch(
                                        FulfillmentController.assign.url(
                                            detail.shipment_id!,
                                        ),
                                        { picker_id: e.target.value || null },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <option value="">Assign picker</option>
                                {pickers.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Button asChild>
                                <Link
                                    href={FulfillmentController.pick.url(
                                        detail.shipment_id!,
                                    )}
                                >
                                    Open on picker
                                </Link>
                            </Button>
                        </>
                    )}
                    {(detail.status === 'loaded' ||
                        detail.status === 'out' ||
                        detail.status === 'ready') && (
                        <Button asChild>
                            <Link
                                href={DeliveryController.show.url(
                                    detail.shipment_id!,
                                )}
                            >
                                {detail.status === 'ready'
                                    ? 'Hand over'
                                    : 'Proof of delivery'}
                            </Link>
                        </Button>
                    )}
                    <Button variant="outline" asChild>
                        <a
                            href={FulfillmentController.ticket.url(
                                detail.shipment_id!,
                            )}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Printer /> Print pick ticket
                        </a>
                    </Button>
                </div>
            )}
        </>
    );
}

Queue.layout = {
    breadcrumbs: [
        {
            title: 'Orders and picking',
            href: FulfillmentController.queue.url(),
        },
    ],
};
