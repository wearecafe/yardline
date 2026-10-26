import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PurchasingController from '@/actions/App/Http/Controllers/PurchasingController';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';

type Props = {
    orders: {
        id: number;
        number: string;
        supplier: string;
        yard: string;
        status: string;
        expected_on: string | null;
        total_cents: number;
        lines: number;
    }[];
    suggestions: {
        product_id: number;
        location_id: number;
        sku: string;
        name: string;
        unit: string;
        yard: string;
        on_hand: string;
        reorder_point: string;
        on_order: string;
        supplier: string | null;
        supplier_id: number | null;
    }[];
    suppliers: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        lead_days: number | null;
    }[];
    locations: { id: number; name: string }[];
};

export const poStatus: Record<string, [string, ChipTone]> = {
    draft: ['Draft', 'neutral'],
    sent: ['Sent', 'info'],
    partial: ['Part received', 'warning'],
    received: ['Received', 'success'],
    cancelled: ['Cancelled', 'neutral'],
};

/**
 * Purchasing: what to reorder, purchase orders, and suppliers.
 */
export default function Purchasing({
    orders,
    suggestions,
    suppliers,
    locations,
}: Props) {
    const [picked, setPicked] = useState<Set<string>>(new Set());
    const po = useForm({
        supplier_id: String(suppliers[0]?.id ?? ''),
        location_id: String(locations[0]?.id ?? ''),
    });
    const supplier = useForm({
        name: '',
        contact_name: '',
        email: '',
        phone: '',
        lead_days: '',
    });

    const toggle = (key: string) =>
        setPicked((s) => {
            const next = new Set(s);
            next.has(key) ? next.delete(key) : next.add(key);

            return next;
        });

    return (
        <>
            <Head title="Purchasing" />
            <PageBody>
                <PageHeader
                    title="Purchasing"
                    description="Reorder what's low, send purchase orders, and receive deliveries against them."
                />

                <Section
                    title={`Below reorder point · ${suggestions.length}`}
                    flush
                >
                    <Table minWidth={760}>
                        <thead>
                            <tr>
                                <Th className="w-10">
                                    <span className="sr-only">Pick</span>
                                </Th>
                                <Th>Product</Th>
                                <Th>Yard</Th>
                                <Th numeric>On hand</Th>
                                <Th numeric>Reorder at</Th>
                                <Th numeric>On order</Th>
                                <Th>Supplier</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {suggestions.length === 0 && (
                                <EmptyRow colSpan={7}>
                                    Nothing below its reorder point.
                                </EmptyRow>
                            )}
                            {suggestions.map((s) => {
                                const key = `${s.product_id}:${s.location_id}`;

                                return (
                                    <tr key={key}>
                                        <Td>
                                            <input
                                                type="checkbox"
                                                aria-label={`Order ${s.name}`}
                                                className="size-4"
                                                checked={picked.has(key)}
                                                onChange={() => toggle(key)}
                                            />
                                        </Td>
                                        <Td>
                                            <div className="font-medium">
                                                {s.name}
                                            </div>
                                            <div className="figure text-xs text-muted-foreground">
                                                {s.sku}
                                            </div>
                                        </Td>
                                        <Td>{s.yard}</Td>
                                        <Td
                                            numeric
                                            className="font-semibold text-[#a16207]"
                                        >
                                            {formatQuantity(s.on_hand)}{' '}
                                            {unitLabel(s.unit)}
                                        </Td>
                                        <Td numeric>
                                            {formatQuantity(s.reorder_point)}
                                        </Td>
                                        <Td numeric>
                                            {Number(s.on_order) > 0
                                                ? formatQuantity(s.on_order)
                                                : '—'}
                                        </Td>
                                        <Td>{s.supplier ?? '—'}</Td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </Table>
                    <form
                        className="flex flex-wrap items-end gap-3 border-t border-border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            po.transform((d) => ({
                                ...d,
                                product_ids: suggestions
                                    .filter(
                                        (s) =>
                                            picked.has(
                                                `${s.product_id}:${s.location_id}`,
                                            ) &&
                                            String(s.location_id) ===
                                                d.location_id,
                                    )
                                    .map((s) => s.product_id),
                            }));
                            po.post(PurchasingController.store.url());
                        }}
                    >
                        <Field
                            label="Supplier"
                            htmlFor="po_supplier"
                            error={po.errors.supplier_id}
                        >
                            <NativeSelect
                                id="po_supplier"
                                required
                                value={po.data.supplier_id}
                                onChange={(e) =>
                                    po.setData('supplier_id', e.target.value)
                                }
                            >
                                {suppliers.length === 0 && (
                                    <option value="">
                                        Add a supplier first
                                    </option>
                                )}
                                {suppliers.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Deliver to" htmlFor="po_yard">
                            <NativeSelect
                                id="po_yard"
                                value={po.data.location_id}
                                onChange={(e) =>
                                    po.setData('location_id', e.target.value)
                                }
                            >
                                {locations.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Button
                            type="submit"
                            disabled={po.processing || suppliers.length === 0}
                        >
                            {picked.size > 0
                                ? `New purchase order with ${picked.size}`
                                : 'New purchase order'}
                        </Button>
                    </form>
                </Section>

                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        title="Purchase orders"
                        flush
                        className="min-w-0 flex-[999_1_560px]"
                    >
                        <Table minWidth={620}>
                            <thead>
                                <tr>
                                    <Th>PO</Th>
                                    <Th>Supplier</Th>
                                    <Th>Expected</Th>
                                    <Th>Status</Th>
                                    <Th numeric>Total</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.length === 0 && (
                                    <EmptyRow colSpan={5}>
                                        No purchase orders yet.
                                    </EmptyRow>
                                )}
                                {orders.map((o) => {
                                    const [label, tone] = poStatus[
                                        o.status
                                    ] ?? [o.status, 'neutral'];

                                    return (
                                        <tr key={o.id}>
                                            <Td>
                                                <Link
                                                    href={PurchasingController.show(
                                                        o.id,
                                                    )}
                                                    className="figure font-medium hover:underline"
                                                >
                                                    {o.number}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {o.lines}{' '}
                                                    {o.lines === 1
                                                        ? 'line'
                                                        : 'lines'}{' '}
                                                    · {o.yard}
                                                </div>
                                            </Td>
                                            <Td>{o.supplier}</Td>
                                            <Td className="whitespace-nowrap">
                                                {o.expected_on
                                                    ? formatDate(o.expected_on)
                                                    : '—'}
                                            </Td>
                                            <Td>
                                                <Chip tone={tone}>{label}</Chip>
                                            </Td>
                                            <Td numeric>
                                                {formatMoney(o.total_cents)}
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </Section>

                    <Section
                        title="Suppliers"
                        className="min-w-0 flex-[1_1_300px]"
                    >
                        <ul className="grid gap-2 text-sm">
                            {suppliers.map((s) => (
                                <li key={s.id}>
                                    <div className="font-medium">{s.name}</div>
                                    <div className="text-muted-foreground">
                                        {[
                                            s.email,
                                            s.phone,
                                            s.lead_days
                                                ? `${s.lead_days}-day lead`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <form
                            className="grid gap-2 border-t border-border pt-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                supplier.transform((d) => ({
                                    ...d,
                                    lead_days: d.lead_days || null,
                                }));
                                supplier.post(
                                    PurchasingController.storeSupplier.url(),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => supplier.reset(),
                                    },
                                );
                            }}
                        >
                            <Input
                                aria-label="Supplier name"
                                required
                                placeholder="Supplier name"
                                value={supplier.data.name}
                                onChange={(e) =>
                                    supplier.setData('name', e.target.value)
                                }
                            />
                            <div className="grid grid-cols-2 gap-2">
                                <Input
                                    aria-label="Email"
                                    type="email"
                                    placeholder="Orders email"
                                    value={supplier.data.email}
                                    onChange={(e) =>
                                        supplier.setData(
                                            'email',
                                            e.target.value,
                                        )
                                    }
                                />
                                <Input
                                    aria-label="Lead time in days"
                                    inputMode="numeric"
                                    placeholder="Lead days"
                                    value={supplier.data.lead_days}
                                    onChange={(e) =>
                                        supplier.setData(
                                            'lead_days',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            {supplier.errors.name && (
                                <p className="text-sm text-danger">
                                    {supplier.errors.name}
                                </p>
                            )}
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={supplier.processing}
                            >
                                Add supplier
                            </Button>
                        </form>
                    </Section>
                </div>
            </PageBody>
        </>
    );
}

Purchasing.layout = {
    breadcrumbs: [{ title: 'Purchasing', href: PurchasingController.index() }],
};
