import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import CycleCountController from '@/actions/App/Http/Controllers/CycleCountController';
import InventoryController from '@/actions/App/Http/Controllers/InventoryController';
import PurchasingController from '@/actions/App/Http/Controllers/PurchasingController';
import StockAdjustmentController from '@/actions/App/Http/Controllers/StockAdjustmentController';
import StockTransferController from '@/actions/App/Http/Controllers/StockTransferController';
import { Chip } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import {
    PageBody,
    PageHeader,
    Section,
    Stat,
    StatGrid,
} from '@/components/yard/page-header';
import { SearchBox } from '@/components/yard/search-box';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatQuantity, unitLabel } from '@/lib/format';

type Row = {
    key: string;
    level_id: number;
    product_id: number;
    location_id: number;
    name: string;
    sku: string;
    unit: string;
    category: string | null;
    yard: string;
    bin: string | null;
    lot: string | null;
    lot_id: number | null;
    on_hand: string;
    committed: string;
    available: string;
    reorder_point: string | null;
    low: boolean;
};

type Movement = {
    id: number;
    type: string;
    quantity: string;
    note: string | null;
    created_at: string;
    product: { id: number; sku: string; name: string; unit: string };
    user: { id: number; name: string } | null;
    location: { id: number; name: string };
};

type Props = {
    rows: Row[];
    stats: {
        low: number;
        pallets_out: number;
        arriving: number;
        variance: number | null;
    };
    locations: {
        id: number;
        name: string;
        code: string;
        is_default: boolean;
    }[];
    categories: string[];
    filters: {
        location_id: string;
        category: string | null;
        search: string | null;
        low: boolean;
    };
    products: {
        id: number;
        sku: string;
        name: string;
        unit: string;
        track_lots: boolean;
    }[];
    lots: {
        id: number;
        product_id: number;
        location_id: number;
        code: string;
        on_hand: string;
    }[];
    openCounts: {
        id: number;
        name: string;
        location_id: number;
        created_at: string;
        lines_count: number;
    }[];
    movements: Movement[];
};

const movementLabels: Record<string, string> = {
    receipt: 'Received',
    adjustment: 'Adjusted',
    count: 'Counted',
    sale: 'Sold',
    rental_out: 'Rented out',
    rental_return: 'Returned',
    return: 'Customer return',
    transfer_out: 'Transferred out',
    transfer_in: 'Transferred in',
    damage: 'Damaged',
};

const modes = [
    {
        value: 'receive',
        label: 'Receive',
        hint: 'Stock that came in without a PO.',
    },
    {
        value: 'count',
        label: 'Count',
        hint: 'Set on-hand to what you counted.',
    },
    { value: 'damage', label: 'Damaged', hint: 'Remove broken or lost stock.' },
    {
        value: 'adjust',
        label: 'Adjust',
        hint: 'Add or remove by any amount (minus to remove).',
    },
] as const;

function AdjustDialog({
    row,
    tracksLots,
    onClose,
}: {
    row: Row;
    tracksLots: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        product_id: row.product_id,
        location_id: row.location_id,
        mode: 'receive' as (typeof modes)[number]['value'],
        quantity: '',
        reorder_point: row.reorder_point
            ? String(Number(row.reorder_point))
            : '',
        note: '',
        lot: row.lot ?? '',
        unit_cost: '',
    });
    const mode = modes.find((m) => m.value === form.data.mode);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(StockAdjustmentController.store.url(), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{row.name}</DialogTitle>
                    <DialogDescription>
                        {formatQuantity(row.on_hand)} {unitLabel(row.unit)} at{' '}
                        {row.yard}
                        {row.lot ? `, run ${row.lot}` : ''}.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <ToggleButtons
                        label="What happened"
                        size="sm"
                        value={form.data.mode}
                        onChange={(v) => form.setData('mode', v)}
                        options={modes.map((m) => ({
                            value: m.value,
                            label: m.label,
                        }))}
                    />
                    <Field
                        label={
                            form.data.mode === 'count'
                                ? 'Counted quantity'
                                : 'Quantity'
                        }
                        htmlFor="quantity"
                        error={form.errors.quantity}
                        hint={mode?.hint}
                    >
                        <Input
                            id="quantity"
                            inputMode="decimal"
                            value={form.data.quantity}
                            onChange={(e) =>
                                form.setData('quantity', e.target.value)
                            }
                            required
                            autoFocus
                        />
                    </Field>
                    {form.data.mode === 'receive' && (
                        <div className="grid grid-cols-2 gap-4">
                            {tracksLots && (
                                <Field
                                    label="Colour run"
                                    htmlFor="lot"
                                    error={form.errors.lot}
                                >
                                    <Input
                                        id="lot"
                                        value={form.data.lot}
                                        placeholder="Run 2611-B"
                                        onChange={(e) =>
                                            form.setData('lot', e.target.value)
                                        }
                                    />
                                </Field>
                            )}
                            <Field
                                label={`Landed cost / ${unitLabel(row.unit)}`}
                                htmlFor="unit_cost"
                                error={form.errors.unit_cost}
                                hint="Updates the product's cost."
                            >
                                <Input
                                    id="unit_cost"
                                    inputMode="decimal"
                                    value={form.data.unit_cost}
                                    onChange={(e) =>
                                        form.setData(
                                            'unit_cost',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-4">
                        <Field
                            label="Reorder at"
                            htmlFor="reorder_point"
                            error={form.errors.reorder_point}
                        >
                            <Input
                                id="reorder_point"
                                inputMode="decimal"
                                value={form.data.reorder_point}
                                onChange={(e) =>
                                    form.setData(
                                        'reorder_point',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Note"
                            htmlFor="note"
                            error={form.errors.note}
                        >
                            <Input
                                id="note"
                                value={form.data.note}
                                placeholder="Slip number, reason"
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Update stock
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CountDialog({
    locations,
    categories,
    onClose,
}: {
    locations: Props['locations'];
    categories: string[];
    onClose: () => void;
}) {
    const form = useForm({
        location_id: String(locations[0]?.id ?? ''),
        bin: '',
        category: '',
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Start a cycle count</DialogTitle>
                    <DialogDescription>
                        Count one section at a time. Differences post when you
                        finish.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            ...d,
                            bin: d.bin || null,
                            category: d.category || null,
                        }));
                        form.post(CycleCountController.store.url());
                    }}
                >
                    <Field label="Yard" htmlFor="count_yard">
                        <NativeSelect
                            id="count_yard"
                            value={form.data.location_id}
                            onChange={(e) =>
                                form.setData('location_id', e.target.value)
                            }
                        >
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field
                            label="Bins starting with"
                            htmlFor="count_bin"
                            error={form.errors.bin}
                            hint="e.g. Row C, Aisle"
                        >
                            <Input
                                id="count_bin"
                                value={form.data.bin}
                                onChange={(e) =>
                                    form.setData('bin', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Category" htmlFor="count_category">
                            <NativeSelect
                                id="count_category"
                                value={form.data.category}
                                onChange={(e) =>
                                    form.setData('category', e.target.value)
                                }
                            >
                                <option value="">All</option>
                                {categories.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Start count
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function TransferDialog({
    locations,
    products,
    lots,
    onClose,
}: {
    locations: Props['locations'];
    products: Props['products'];
    lots: Props['lots'];
    onClose: () => void;
}) {
    const form = useForm({
        from_location_id: String(locations[0]?.id ?? ''),
        to_location_id: String(locations[1]?.id ?? locations[0]?.id ?? ''),
        product_id: '',
        lot_id: '',
        quantity: '',
        notes: '',
    });
    const product = products.find((p) => String(p.id) === form.data.product_id);
    const productLots = lots.filter(
        (l) =>
            String(l.product_id) === form.data.product_id &&
            String(l.location_id) === form.data.from_location_id,
    );
    const errors = form.errors as Record<string, string>;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Move stock between yards</DialogTitle>
                </DialogHeader>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            from_location_id: d.from_location_id,
                            to_location_id: d.to_location_id,
                            notes: d.notes || null,
                            lines: [
                                {
                                    product_id: d.product_id,
                                    quantity: d.quantity,
                                    lot_id: d.lot_id || null,
                                },
                            ],
                        }));
                        form.post(StockTransferController.store.url(), {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid grid-cols-2 gap-4">
                        <Field
                            label="From"
                            htmlFor="tr_from"
                            error={errors.from_location_id}
                        >
                            <NativeSelect
                                id="tr_from"
                                value={form.data.from_location_id}
                                onChange={(e) =>
                                    form.setData(
                                        'from_location_id',
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
                            label="To"
                            htmlFor="tr_to"
                            error={errors.to_location_id}
                        >
                            <NativeSelect
                                id="tr_to"
                                value={form.data.to_location_id}
                                onChange={(e) =>
                                    form.setData(
                                        'to_location_id',
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
                    </div>
                    <Field
                        label="Product"
                        htmlFor="tr_product"
                        error={errors['lines.0.product_id']}
                    >
                        <NativeSelect
                            id="tr_product"
                            required
                            value={form.data.product_id}
                            onChange={(e) =>
                                form.setData((d) => ({
                                    ...d,
                                    product_id: e.target.value,
                                    lot_id: '',
                                }))
                            }
                        >
                            <option value="">Pick a product</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name} ({p.sku})
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        {product?.track_lots && (
                            <Field label="Colour run" htmlFor="tr_lot">
                                <NativeSelect
                                    id="tr_lot"
                                    value={form.data.lot_id}
                                    onChange={(e) =>
                                        form.setData('lot_id', e.target.value)
                                    }
                                >
                                    <option value="">Any</option>
                                    {productLots.map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {l.code} (
                                            {formatQuantity(l.on_hand)})
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        )}
                        <Field
                            label={`Quantity${product ? ` (${unitLabel(product.unit)})` : ''}`}
                            htmlFor="tr_qty"
                            error={errors['lines.0.quantity'] ?? errors.lines}
                        >
                            <Input
                                id="tr_qty"
                                required
                                inputMode="decimal"
                                value={form.data.quantity}
                                onChange={(e) =>
                                    form.setData('quantity', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Move stock
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Stock overview (yard mockup 2).
 */
export default function Inventory({
    rows,
    stats,
    locations,
    categories,
    filters,
    products,
    lots,
    openCounts,
    movements,
}: Props) {
    const [adjusting, setAdjusting] = useState<Row | null>(null);
    const [dialog, setDialog] = useState<'count' | 'transfer' | null>(null);
    const filter = (patch: Partial<Props['filters']>) =>
        router.get(
            InventoryController.index.url(),
            {
                ...filters,
                ...patch,
                low: (patch.low ?? filters.low) ? 1 : undefined,
            },
            { preserveState: true, replace: true },
        );
    const tracksLots = (id: number) =>
        products.find((p) => p.id === id)?.track_lots ?? false;

    return (
        <>
            <Head title="Stock" />
            <PageBody>
                <PageHeader
                    title="Stock"
                    description="On hand, committed and available by yard, bin and colour run."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDialog('transfer')}
                            >
                                Move between yards
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setDialog('count')}
                            >
                                Start cycle count
                            </Button>
                            <Button asChild>
                                <Link href={PurchasingController.index()}>
                                    Receive delivery
                                </Link>
                            </Button>
                        </>
                    }
                />
                <StatGrid>
                    <button
                        type="button"
                        onClick={() => filter({ low: !filters.low })}
                        className="text-left"
                    >
                        <Stat
                            label="Products below reorder point"
                            value={stats.low}
                            tone={stats.low > 0 ? 'warning' : undefined}
                        />
                    </button>
                    <Stat
                        label="Pallets out on deposit"
                        value={stats.pallets_out}
                    />
                    <Stat
                        label="Purchase orders arriving this week"
                        value={stats.arriving}
                    />
                    <Stat
                        label="Count variance, last 30 days"
                        value={
                            stats.variance === null
                                ? '—'
                                : `${stats.variance > 0 ? '+' : stats.variance < 0 ? '−' : ''}${Math.abs(stats.variance)}%`
                        }
                    />
                </StatGrid>

                {openCounts.length > 0 && (
                    <Section title="Counts in progress">
                        <ul className="flex flex-wrap gap-2">
                            {openCounts.map((c) => (
                                <li key={c.id}>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={CycleCountController.show(
                                                c.id,
                                            )}
                                        >
                                            {c.name} · {c.lines_count} items
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <SearchBox
                        value={filters.search ?? undefined}
                        placeholder="Search"
                        extra={{
                            location_id: filters.location_id,
                            category: filters.category ?? undefined,
                            low: filters.low ? 1 : undefined,
                        }}
                    />
                    <NativeSelect
                        aria-label="Location"
                        className="w-auto"
                        value={filters.location_id}
                        onChange={(e) =>
                            filter({ location_id: e.target.value })
                        }
                    >
                        <option value="all">All yard locations</option>
                        {locations.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Category"
                        className="w-auto"
                        value={filters.category ?? ''}
                        onChange={(e) =>
                            filter({ category: e.target.value || null })
                        }
                    >
                        <option value="">All categories</option>
                        {categories.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </NativeSelect>
                    {filters.low && (
                        <Chip tone="warning">Below reorder point</Chip>
                    )}
                </div>

                <Section flush>
                    <Table minWidth={880}>
                        <thead>
                            <tr>
                                <Th>Product</Th>
                                <Th>Location</Th>
                                <Th>Lot / colour run</Th>
                                <Th numeric>On hand</Th>
                                <Th numeric>Committed</Th>
                                <Th numeric>Available</Th>
                                <Th>Status</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <EmptyRow colSpan={7}>
                                    No stock matches.
                                </EmptyRow>
                            )}
                            {rows.map((row) => (
                                <tr
                                    key={row.key}
                                    className="cursor-pointer hover:bg-accent/60"
                                    onClick={() => setAdjusting(row)}
                                >
                                    <Td>
                                        <div className="font-medium">
                                            {row.name}
                                        </div>
                                        <div className="figure text-xs text-muted-foreground">
                                            {row.sku}
                                        </div>
                                    </Td>
                                    <Td>
                                        {row.bin ?? '—'}
                                        {locations.length > 1 && (
                                            <div className="text-xs text-muted-foreground">
                                                {row.yard}
                                            </div>
                                        )}
                                    </Td>
                                    <Td className="figure">{row.lot ?? '—'}</Td>
                                    <Td numeric>
                                        {formatQuantity(row.on_hand)}{' '}
                                        {unitLabel(row.unit)}
                                    </Td>
                                    <Td numeric className="text-subtle">
                                        {Number(row.committed) > 0
                                            ? `${formatQuantity(row.committed)} ${unitLabel(row.unit)}`
                                            : '0'}
                                    </Td>
                                    <Td numeric className="font-semibold">
                                        {formatQuantity(row.available)}{' '}
                                        {unitLabel(row.unit)}
                                    </Td>
                                    <Td>
                                        {row.low ? (
                                            <Chip tone="warning">Reorder</Chip>
                                        ) : (
                                            <Chip tone="success">OK</Chip>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Section>

                <Section title="Recent movements" flush>
                    <Table>
                        <tbody>
                            {movements.length === 0 && (
                                <EmptyRow colSpan={4}>
                                    No movements yet.
                                </EmptyRow>
                            )}
                            {movements.map((m) => (
                                <tr key={m.id}>
                                    <Td className="whitespace-nowrap text-subtle">
                                        {formatDate(m.created_at)}
                                    </Td>
                                    <Td>
                                        <div className="font-medium">
                                            {m.product.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {[
                                                movementLabels[m.type] ??
                                                    m.type,
                                                m.location.name,
                                                m.note,
                                                m.user?.name,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </Td>
                                    <Td
                                        numeric
                                        className={
                                            Number(m.quantity) < 0
                                                ? 'text-danger'
                                                : 'text-success'
                                        }
                                    >
                                        {Number(m.quantity) > 0 ? '+' : ''}
                                        {formatQuantity(m.quantity)}{' '}
                                        {unitLabel(m.product.unit)}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Section>
            </PageBody>

            {adjusting && (
                <AdjustDialog
                    row={adjusting}
                    tracksLots={tracksLots(adjusting.product_id)}
                    onClose={() => setAdjusting(null)}
                />
            )}
            {dialog === 'count' && (
                <CountDialog
                    locations={locations}
                    categories={categories}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'transfer' && (
                <TransferDialog
                    locations={locations}
                    products={products}
                    lots={lots}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

Inventory.layout = {
    breadcrumbs: [{ title: 'Stock', href: InventoryController.index() }],
};
