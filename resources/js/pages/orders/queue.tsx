import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Printer, UserRound } from 'lucide-react';
import { useState } from 'react';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import FulfillmentController from '@/actions/App/Http/Controllers/FulfillmentController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import PickerSwitchController from '@/actions/App/Http/Controllers/PickerSwitchController';
import { Chip } from '@/components/yard/chip';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, formatMoney, formatQuantity } from '@/lib/format';
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
    awaiting_check: string | null;
    sent_back: boolean;
};

type NextStep = { action: 'picked' | 'load' | 'check'; label: string } | null;

type CheckPanel = {
    stage: 'picked' | 'loaded';
    label: string;
    completed_by: string | null;
    can_check: boolean;
    reason: string | null;
} | null;

type SentBack = {
    stage: string;
    reason: string | null;
    by: string | null;
    at: string;
} | null;

type SignOff = {
    id: number;
    stage: string;
    outcome: 'signed_off' | 'rejected';
    by: string | null;
    at: string;
    notes: string | null;
    reason: string | null;
    photo_url: string | null;
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
    picked_quantity?: string | null;
};

type Detail = Card & {
    items: PickItem[];
    weight_kg: number;
    deposits: { count: number; cents: number | null; label: string } | null;
    yard: string;
    price_tier: string | null;
    delivery_address: { line1?: string | null; city?: string | null } | null;
    site_notes: string | null;
    zone: string | null;
    // Left out for order pickers, who don't see money.
    delivery_fee_cents: number | null;
    payment_status: string | null;
    settlement: string | null;
    balance_cents: number | null;
    picker_id: number | null;
    next_step: NextStep;
    check: CheckPanel;
    sent_back: SentBack;
    sign_offs: SignOff[];
    released: boolean;
    can_cancel: boolean;
};

type Props = {
    columns: { key: string; title: string; cards: Card[] }[];
    detail: Detail | null;
    selected: string | null;
    pickers: { id: number; name: string }[];
    switchable: { id: number; name: string }[];
    checksOnly: boolean;
    awaitingChecks: number;
    stages: Record<'picking' | 'picked' | 'loaded' | 'out', boolean>;
    today: string;
};

const time = (iso: string) =>
    new Date(iso)
        .toLocaleTimeString('en-CA', { hour: 'numeric', minute: '2-digit' })
        .replace(/\s?[ap]\.?m\.?/i, '');

function select(key: string, checksOnly: boolean) {
    router.get(
        FulfillmentController.queue.url(),
        { card: key, ...(checksOnly ? { checks: 1 } : {}) },
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
 * a pick list, and moves across the yard's stages as it's picked and
 * loaded. Loads waiting for a quality check can be filtered out.
 */
export default function Queue({
    columns,
    detail,
    selected,
    pickers,
    switchable,
    checksOnly,
    awaitingChecks,
    stages,
    today,
}: Props) {
    const { auth } = usePage().props;
    const total = columns.reduce((n, c) => n + c.cards.length, 0);

    return (
        <>
            <Head title="Orders and picking" />
            <PageBody>
                <PageHeader
                    title="Orders and picking"
                    description={`Every confirmed order lands here as a pick list. Today, ${today}.`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {switchable.length > 0 && (
                                <SwitchPicker pickers={switchable} />
                            )}
                            {!auth.can.pick && (
                                <Button variant="outline" asChild>
                                    <Link href={OrderController.index()}>
                                        All orders
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant={checksOnly ? 'default' : 'outline'}
                        size="sm"
                        aria-pressed={checksOnly}
                        onClick={() =>
                            router.get(
                                FulfillmentController.queue.url(),
                                checksOnly ? {} : { checks: 1 },
                                { preserveScroll: true, replace: true },
                            )
                        }
                    >
                        Awaiting check
                        <span className="figure ml-1 rounded-full bg-card px-1.5 text-xs text-foreground">
                            {awaitingChecks}
                        </span>
                    </Button>
                    {checksOnly && (
                        <span className="text-sm text-muted-foreground">
                            Showing only loads waiting for a sign-off.
                        </span>
                    )}
                </div>

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
                                        onClick={() =>
                                            select(card.key, checksOnly)
                                        }
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
                                            {card.awaiting_check && (
                                                <Chip tone="warning">
                                                    Awaiting check
                                                </Chip>
                                            )}
                                            {card.sent_back && (
                                                <Chip tone="danger">
                                                    Sent back
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
                                {checksOnly
                                    ? 'Nothing is waiting for a check.'
                                    : 'No confirmed orders waiting. Confirmed orders show up here to pick.'}
                            </p>
                        )}
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_320px] flex-col gap-4 rounded-[10px] border border-border bg-card p-5 lg:sticky lg:top-4">
                        {detail ? (
                            <PickPanel
                                detail={detail}
                                pickers={pickers}
                                stages={stages}
                                isPicker={auth.can.pick}
                                readOnly={!auth.can.workQueue}
                            />
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
    stages,
    isPicker,
    readOnly,
}: {
    detail: Detail;
    pickers: Props['pickers'];
    stages: Props['stages'];
    isPicker: boolean;
    /** Can look at the queue but not pick, load or cancel. */
    readOnly: boolean;
}) {
    const isNew = detail.shipment_id === null;
    const atYard =
        detail.shipment_id !== null &&
        !detail.released &&
        ['picking', 'picked', 'loaded'].includes(detail.status);

    return (
        <>
            <div>
                <div className="text-xs text-muted-foreground">
                    {isPicker ? (
                        <span className="figure">{detail.number}</span>
                    ) : (
                        <Link
                            href={OrderController.show(detail.order_id)}
                            className="figure hover:underline"
                        >
                            {detail.number}
                        </Link>
                    )}
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

            {detail.sent_back && (
                <div className="rounded-lg bg-danger-soft p-3 text-sm text-danger">
                    <div className="font-semibold">
                        Sent back at {detail.sent_back.stage}
                        {detail.sent_back.by && ` by ${detail.sent_back.by}`}
                    </div>
                    {detail.sent_back.reason}
                </div>
            )}

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
                                {item.picked_quantity !== null &&
                                    item.picked_quantity !== undefined &&
                                    Number(item.picked_quantity) !==
                                        Number(item.quantity) && (
                                        <div className="text-xs font-normal text-muted-foreground">
                                            picked{' '}
                                            {formatQuantity(
                                                item.picked_quantity,
                                            )}
                                        </div>
                                    )}
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
                {detail.zone !== null ||
                (detail.delivery_fee_cents ?? 0) > 0 ? (
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
                {detail.balance_cents !== null && (
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
                )}
            </dl>

            {detail.check && <CheckForm detail={detail} check={detail.check} />}

            {isNew ? (
                !readOnly && (
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const picker = new FormData(e.currentTarget).get(
                                'picker_id',
                            );
                            router.post(
                                FulfillmentController.start.url(
                                    detail.order_id,
                                ),
                                { picker_id: picker || null },
                            );
                        }}
                    >
                        {stages.picking && !isPicker && (
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
                        )}
                        <Button type="submit">
                            {!stages.picking
                                ? 'Open pick list'
                                : isPicker
                                  ? 'Claim and start picking'
                                  : 'Start picking'}
                        </Button>
                    </form>
                )
            ) : (
                <div className="grid gap-2">
                    {detail.status === 'picking' &&
                        stages.picking &&
                        !readOnly &&
                        (isPicker ? (
                            detail.picker_id === null && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.patch(
                                            FulfillmentController.assign.url(
                                                detail.shipment_id!,
                                            ),
                                            { picker_id: pickers[0]?.id },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Claim this order
                                </Button>
                            )
                        ) : (
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
                        ))}
                    {detail.status === 'picking' && !readOnly && (
                        <Button asChild>
                            <Link
                                href={FulfillmentController.pick.url(
                                    detail.shipment_id!,
                                )}
                            >
                                Open on picker
                            </Link>
                        </Button>
                    )}
                    {detail.status === 'picked' &&
                        !readOnly &&
                        detail.next_step?.action === 'load' && (
                            <Button
                                onClick={() =>
                                    router.post(
                                        FulfillmentController.load.url(
                                            detail.shipment_id!,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {detail.next_step.label}
                            </Button>
                        )}
                    {detail.released &&
                        ['loaded', 'out', 'ready'].includes(detail.status) &&
                        !isPicker && (
                            <Button asChild>
                                <Link
                                    href={DeliveryController.show.url(
                                        detail.shipment_id!,
                                    )}
                                >
                                    {detail.mode === 'Pickup'
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
                    {atYard && detail.can_cancel && !readOnly && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                if (
                                    confirm(
                                        'Cancel this pick list? The order goes back to New.',
                                    )
                                ) {
                                    router.post(
                                        FulfillmentController.cancel.url(
                                            detail.shipment_id!,
                                        ),
                                    );
                                }
                            }}
                        >
                            Cancel pick list
                        </Button>
                    )}
                </div>
            )}

            {detail.sign_offs.length > 0 && (
                <SignOffs list={detail.sign_offs} />
            )}
        </>
    );
}

/**
 * A load waiting at Picked or Loaded: someone allowed to check it signs
 * off, or sends it back a stage with the reason.
 */
function CheckForm({
    detail,
    check,
}: {
    detail: Detail;
    check: NonNullable<CheckPanel>;
}) {
    const [rejecting, setRejecting] = useState(false);
    const signOff = useForm<{ notes: string; photo: File | null }>({
        notes: '',
        photo: null,
    });
    const reject = useForm({ reason: '' });

    return (
        <section className="grid gap-3 rounded-lg border-2 border-warning bg-warning-soft/40 p-3">
            <div>
                <h3 className="text-sm font-semibold">
                    Awaiting check at {check.label}
                </h3>
                <p className="text-xs text-muted-foreground">
                    {check.completed_by
                        ? `Marked ${check.label.toLowerCase()} by ${check.completed_by}. `
                        : ''}
                    Check it against the pick list before it moves on.
                </p>
            </div>

            {!check.can_check ? (
                <p className="text-sm text-muted-foreground">{check.reason}</p>
            ) : rejecting ? (
                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        reject.post(
                            FulfillmentController.reject.url(
                                detail.shipment_id!,
                            ),
                            { preserveScroll: true },
                        );
                    }}
                >
                    <Field
                        label="What's wrong?"
                        htmlFor="reject-reason"
                        error={reject.errors.reason}
                    >
                        <Input
                            id="reject-reason"
                            required
                            value={reject.data.reason}
                            onChange={(e) =>
                                reject.setData('reason', e.target.value)
                            }
                            placeholder="2 pallets of the wrong colour run"
                        />
                    </Field>
                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={reject.processing}
                        >
                            Send back to{' '}
                            {check.stage === 'loaded' ? 'Picked' : 'Picking'}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setRejecting(false)}
                        >
                            Back
                        </Button>
                    </div>
                </form>
            ) : (
                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        signOff.post(
                            FulfillmentController.signOff.url(
                                detail.shipment_id!,
                            ),
                            { preserveScroll: true, forceFormData: true },
                        );
                    }}
                >
                    <Field
                        label="Notes (optional)"
                        htmlFor="check-notes"
                        error={signOff.errors.notes}
                    >
                        <Input
                            id="check-notes"
                            value={signOff.data.notes}
                            onChange={(e) =>
                                signOff.setData('notes', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Photo (optional)"
                        htmlFor="check-photo"
                        error={signOff.errors.photo}
                    >
                        <Input
                            id="check-photo"
                            type="file"
                            accept="image/*"
                            capture="environment"
                            onChange={(e) =>
                                signOff.setData(
                                    'photo',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </Field>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={signOff.processing}>
                            Sign off
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setRejecting(true)}
                        >
                            Reject
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}

function SignOffs({ list }: { list: SignOff[] }) {
    return (
        <section className="border-t border-border pt-3">
            <h3 className="mb-2 text-sm font-semibold">Checks</h3>
            <ul className="grid gap-1.5 text-sm">
                {list.map((c) => (
                    <li key={c.id}>
                        <span
                            className={cn(
                                'font-medium',
                                c.outcome === 'rejected' && 'text-danger',
                            )}
                        >
                            {c.stage}{' '}
                            {c.outcome === 'signed_off'
                                ? 'checked'
                                : 'sent back'}
                        </span>{' '}
                        <span className="text-muted-foreground">
                            by {c.by ?? 'someone'} · {formatDateTime(c.at)}
                        </span>
                        {(c.reason || c.notes) && (
                            <div className="text-muted-foreground">
                                {c.reason ?? c.notes}
                            </div>
                        )}
                        {c.photo_url && (
                            <a
                                href={c.photo_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-xs underline underline-offset-4"
                            >
                                Photo
                            </a>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Yard tablets are shared: an order picker switches in with their PIN.
 */
function SwitchPicker({ pickers }: { pickers: Props['switchable'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ user_id: String(pickers[0]?.id ?? ''), pin: '' });

    if (!open) {
        return (
            <Button variant="outline" onClick={() => setOpen(true)}>
                <UserRound /> Switch picker
            </Button>
        );
    }

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(PickerSwitchController.url(), {
                    onFinish: () => form.reset('pin'),
                });
            }}
        >
            <NativeSelect
                aria-label="Picker"
                value={form.data.user_id}
                onChange={(e) => form.setData('user_id', e.target.value)}
                className="w-auto"
            >
                {pickers.map((p) => (
                    <option key={p.id} value={p.id}>
                        {p.name}
                    </option>
                ))}
            </NativeSelect>
            <div className="grid gap-1">
                <Input
                    aria-label="PIN"
                    inputMode="numeric"
                    autoComplete="off"
                    type="password"
                    maxLength={6}
                    placeholder="PIN"
                    className="w-24"
                    value={form.data.pin}
                    onChange={(e) =>
                        form.setData('pin', e.target.value.replace(/\D/g, ''))
                    }
                />
                {form.errors.pin && (
                    <span className="text-xs text-danger">
                        {form.errors.pin}
                    </span>
                )}
            </div>
            <Button type="submit" disabled={form.processing}>
                Switch
            </Button>
            <Button
                type="button"
                variant="ghost"
                onClick={() => setOpen(false)}
            >
                Cancel
            </Button>
        </form>
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
