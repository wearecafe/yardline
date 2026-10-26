import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import PurchasingController from '@/actions/App/Http/Controllers/PurchasingController';
import InputError from '@/components/input-error';
import { Chip } from '@/components/yard/chip';
import { Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    centsToInput,
    formatDate,
    formatDateTime,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';
import { poStatus } from './index';

type Line = {
    id: number;
    product_id: number;
    sku: string;
    name: string;
    unit: string;
    track_lots: boolean;
    quantity: string;
    received_quantity: string;
    outstanding: string;
    unit_cost_cents: number;
    current_cost_cents: number | null;
};

type Props = {
    po: {
        id: number;
        number: string;
        status: string;
        supplier_reference: string | null;
        notes: string | null;
        total_cents: number;
        location_id: number;
        supplier_id: number;
        expected_on: string | null;
        sent_at: string | null;
        received_at: string | null;
        supplier: {
            id: number;
            name: string;
            email: string | null;
            phone: string | null;
            contact_name: string | null;
        };
        yard: string;
        lines: Line[];
    };
    products: {
        id: number;
        sku: string;
        name: string;
        unit: string;
        cost_cents: number | null;
        supplier_id: number | null;
    }[];
    locations: { id: number; name: string }[];
};

/**
 * A purchase order: edit it while it's a draft, send it, and receive what
 * comes in (with the colour run for lot-tracked products).
 */
export default function PurchaseOrderPage({ po, products, locations }: Props) {
    const editable = po.status === 'draft' || po.status === 'sent';
    const receiving = po.status === 'sent' || po.status === 'partial';
    const [label, tone] = poStatus[po.status] ?? [po.status, 'neutral'];

    const form = useForm({
        location_id: String(po.location_id),
        expected_on: po.expected_on ?? '',
        supplier_reference: po.supplier_reference ?? '',
        notes: po.notes ?? '',
        lines: po.lines.map((l) => ({
            id: l.id as number | null,
            product_id: String(l.product_id),
            quantity: String(Number(l.quantity)),
            unit_cost: centsToInput(l.unit_cost_cents),
        })),
    });

    const receive = useForm({
        lines: po.lines.map((l) => ({
            line_id: l.id,
            quantity:
                Number(l.outstanding) > 0 ? String(Number(l.outstanding)) : '',
            lot: '',
            unit_cost: '',
        })),
    });

    const total = form.data.lines.reduce(
        (sum, l) =>
            sum +
            Math.round(
                Number(l.quantity || 0) * Number(l.unit_cost || 0) * 100,
            ),
        0,
    );
    const errors = form.errors as Record<string, string>;
    const receiveErrors = receive.errors as Record<string, string>;

    return (
        <>
            <Head title={po.number} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <Link
                            href={PurchasingController.index()}
                            className="hover:underline"
                        >
                            Purchasing
                        </Link>
                    }
                    title={
                        <span className="flex flex-wrap items-center gap-3">
                            {po.number} <Chip tone={tone}>{label}</Chip>
                        </span>
                    }
                    description={`${po.supplier.name} → ${po.yard}${po.sent_at ? ` · sent ${formatDateTime(po.sent_at)}` : ''}${po.received_at ? ` · received ${formatDate(po.received_at)}` : ''}`}
                    actions={
                        <>
                            {po.status !== 'received' &&
                                po.status !== 'cancelled' && (
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                PurchasingController.cancel.url(
                                                    po.id,
                                                ),
                                            )
                                        }
                                    >
                                        Cancel PO
                                    </Button>
                                )}
                            {editable && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            PurchasingController.send.url(
                                                po.id,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {po.supplier.email
                                        ? `Email to ${po.supplier.email}`
                                        : 'Mark as sent'}
                                </Button>
                            )}
                        </>
                    }
                />

                {editable && (
                    <form
                        className="grid gap-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(PurchasingController.update.url(po.id), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Section title="Order" flush>
                            <Table minWidth={680}>
                                <thead>
                                    <tr>
                                        <Th>Product</Th>
                                        <Th numeric>Quantity</Th>
                                        <Th numeric>Landed cost</Th>
                                        <Th numeric>Amount</Th>
                                        <Th className="w-10">
                                            <span className="sr-only">
                                                Remove
                                            </span>
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {form.data.lines.map((line, i) => {
                                        const product = products.find(
                                            (p) =>
                                                String(p.id) ===
                                                line.product_id,
                                        );
                                        const set = (
                                            patch: Partial<typeof line>,
                                        ) =>
                                            form.setData(
                                                'lines',
                                                form.data.lines.map((l, j) =>
                                                    j === i
                                                        ? { ...l, ...patch }
                                                        : l,
                                                ),
                                            );

                                        return (
                                            <tr key={line.id ?? `new-${i}`}>
                                                <Td>
                                                    <NativeSelect
                                                        aria-label="Product"
                                                        value={line.product_id}
                                                        onChange={(e) => {
                                                            const p =
                                                                products.find(
                                                                    (x) =>
                                                                        String(
                                                                            x.id,
                                                                        ) ===
                                                                        e.target
                                                                            .value,
                                                                );
                                                            set({
                                                                product_id:
                                                                    e.target
                                                                        .value,
                                                                unit_cost:
                                                                    line.unit_cost ||
                                                                    centsToInput(
                                                                        p?.cost_cents ??
                                                                            null,
                                                                    ),
                                                            });
                                                        }}
                                                    >
                                                        <option value="">
                                                            Pick a product
                                                        </option>
                                                        {products.map((p) => (
                                                            <option
                                                                key={p.id}
                                                                value={p.id}
                                                            >
                                                                {p.name} (
                                                                {p.sku})
                                                            </option>
                                                        ))}
                                                    </NativeSelect>
                                                </Td>
                                                <Td numeric>
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <Input
                                                            aria-label="Quantity"
                                                            inputMode="decimal"
                                                            value={
                                                                line.quantity
                                                            }
                                                            onChange={(e) =>
                                                                set({
                                                                    quantity:
                                                                        e.target
                                                                            .value,
                                                                })
                                                            }
                                                            className="figure w-24 text-right"
                                                        />
                                                        <span className="w-10 text-left text-muted-foreground">
                                                            {product
                                                                ? unitLabel(
                                                                      product.unit,
                                                                  )
                                                                : ''}
                                                        </span>
                                                    </div>
                                                </Td>
                                                <Td numeric>
                                                    <Input
                                                        aria-label="Landed cost"
                                                        inputMode="decimal"
                                                        value={line.unit_cost}
                                                        onChange={(e) =>
                                                            set({
                                                                unit_cost:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                        className="figure ml-auto w-28 text-right"
                                                    />
                                                </Td>
                                                <Td numeric>
                                                    {formatMoney(
                                                        Math.round(
                                                            Number(
                                                                line.quantity ||
                                                                    0,
                                                            ) *
                                                                Number(
                                                                    line.unit_cost ||
                                                                        0,
                                                                ) *
                                                                100,
                                                        ),
                                                    )}
                                                </Td>
                                                <Td>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Remove line"
                                                        onClick={() =>
                                                            form.setData(
                                                                'lines',
                                                                form.data.lines.filter(
                                                                    (_, j) =>
                                                                        j !== i,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <X />
                                                    </Button>
                                                </Td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </Table>
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        form.setData('lines', [
                                            ...form.data.lines,
                                            {
                                                id: null,
                                                product_id: '',
                                                quantity: '',
                                                unit_cost: '',
                                            },
                                        ])
                                    }
                                >
                                    <Plus /> Add line
                                </Button>
                                <span className="figure font-semibold">
                                    {formatMoney(total)}
                                </span>
                            </div>
                        </Section>
                        <Section>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Deliver to" htmlFor="po_location">
                                    <NativeSelect
                                        id="po_location"
                                        value={form.data.location_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'location_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {locations.map((l) => (
                                            <option key={l.id} value={l.id}>
                                                {l.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field
                                    label="Expected"
                                    htmlFor="po_expected"
                                    error={errors.expected_on}
                                >
                                    <Input
                                        id="po_expected"
                                        type="date"
                                        value={form.data.expected_on}
                                        onChange={(e) =>
                                            form.setData(
                                                'expected_on',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Supplier's reference"
                                    htmlFor="po_ref"
                                >
                                    <Input
                                        id="po_ref"
                                        value={form.data.supplier_reference}
                                        onChange={(e) =>
                                            form.setData(
                                                'supplier_reference',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Notes for the supplier"
                                    htmlFor="po_notes"
                                    className="sm:col-span-3"
                                >
                                    <Input
                                        id="po_notes"
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Deliver to the north gate. Call 30 min out."
                                    />
                                </Field>
                            </div>
                            {Object.entries(errors)
                                .filter(([k]) => k.startsWith('lines'))
                                .slice(0, 2)
                                .map(([k, m]) => (
                                    <InputError key={k} message={m} />
                                ))}
                            <div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    Save purchase order
                                </Button>
                            </div>
                        </Section>
                    </form>
                )}

                {(receiving || po.status === 'received') && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            receive.post(
                                PurchasingController.receive.url(po.id),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Section
                            title={receiving ? 'Receive delivery' : 'Received'}
                            flush
                        >
                            <Table minWidth={760}>
                                <thead>
                                    <tr>
                                        <Th>Product</Th>
                                        <Th numeric>Ordered</Th>
                                        <Th numeric>Received</Th>
                                        {receiving && <Th numeric>Now</Th>}
                                        {receiving && <Th>Colour run</Th>}
                                        {receiving && (
                                            <Th numeric>Landed cost</Th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {po.lines.map((l, i) => {
                                        const row = receive.data.lines[i];
                                        const set = (
                                            patch: Partial<typeof row>,
                                        ) =>
                                            receive.setData(
                                                'lines',
                                                receive.data.lines.map(
                                                    (r, j) =>
                                                        j === i
                                                            ? { ...r, ...patch }
                                                            : r,
                                                ),
                                            );

                                        return (
                                            <tr key={l.id}>
                                                <Td>
                                                    <div className="font-medium">
                                                        {l.name}
                                                    </div>
                                                    <div className="figure text-xs text-muted-foreground">
                                                        {l.sku}
                                                    </div>
                                                </Td>
                                                <Td numeric>
                                                    {formatQuantity(l.quantity)}{' '}
                                                    {unitLabel(l.unit)}
                                                </Td>
                                                <Td numeric>
                                                    {formatQuantity(
                                                        l.received_quantity,
                                                    )}
                                                </Td>
                                                {receiving && (
                                                    <Td numeric>
                                                        <Input
                                                            aria-label={`${l.name} received now`}
                                                            inputMode="decimal"
                                                            disabled={
                                                                Number(
                                                                    l.outstanding,
                                                                ) <= 0
                                                            }
                                                            value={row.quantity}
                                                            onChange={(e) =>
                                                                set({
                                                                    quantity:
                                                                        e.target
                                                                            .value,
                                                                })
                                                            }
                                                            className="figure ml-auto w-24 text-right"
                                                        />
                                                    </Td>
                                                )}
                                                {receiving && (
                                                    <Td>
                                                        {l.track_lots ? (
                                                            <Input
                                                                aria-label={`${l.name} colour run`}
                                                                placeholder="Run on the tag"
                                                                value={row.lot}
                                                                onChange={(e) =>
                                                                    set({
                                                                        lot: e
                                                                            .target
                                                                            .value,
                                                                    })
                                                                }
                                                                className="w-36"
                                                            />
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                    </Td>
                                                )}
                                                {receiving && (
                                                    <Td numeric>
                                                        <Input
                                                            aria-label={`${l.name} landed cost`}
                                                            inputMode="decimal"
                                                            placeholder={centsToInput(
                                                                l.unit_cost_cents,
                                                            )}
                                                            value={
                                                                row.unit_cost
                                                            }
                                                            onChange={(e) =>
                                                                set({
                                                                    unit_cost:
                                                                        e.target
                                                                            .value,
                                                                })
                                                            }
                                                            className="figure ml-auto w-28 text-right"
                                                        />
                                                        {l.current_cost_cents !==
                                                            null &&
                                                            Number(
                                                                row.unit_cost ||
                                                                    0,
                                                            ) > 0 &&
                                                            Math.round(
                                                                Number(
                                                                    row.unit_cost,
                                                                ) * 100,
                                                            ) !==
                                                                l.current_cost_cents && (
                                                                <div className="mt-1 text-xs text-[#a16207]">
                                                                    Was{' '}
                                                                    {formatMoney(
                                                                        l.current_cost_cents,
                                                                    )}
                                                                    : margins
                                                                    will be
                                                                    flagged
                                                                </div>
                                                            )}
                                                    </Td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </Table>
                            {receiving && (
                                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                                    <div>
                                        {Object.values(receiveErrors)
                                            .slice(0, 2)
                                            .map((m) => (
                                                <InputError
                                                    key={m}
                                                    message={m}
                                                />
                                            ))}
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={receive.processing}
                                    >
                                        Receive into {po.yard}
                                    </Button>
                                </div>
                            )}
                        </Section>
                    </form>
                )}
            </PageBody>
        </>
    );
}

PurchaseOrderPage.layout = {
    breadcrumbs: [{ title: 'Purchasing', href: PurchasingController.index() }],
};
