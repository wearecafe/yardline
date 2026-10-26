import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InventoryController from '@/actions/App/Http/Controllers/InventoryController';
import StockAdjustmentController from '@/actions/App/Http/Controllers/StockAdjustmentController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { SearchBox } from '@/components/yard/search-box';
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
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

type StockRow = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    kind: 'sale' | 'rental';
    on_hand: string;
    reorder_point: string | null;
};

type Movement = {
    id: number;
    quantity: string;
    type: string;
    note: string | null;
    created_at: string;
    product: { id: number; sku: string; name: string; unit: string };
    user: { id: number; name: string } | null;
};

const movementLabels: Record<string, string> = {
    receipt: 'Received',
    adjustment: 'Adjusted',
    count: 'Counted',
    sale: 'Sold',
    rental_out: 'Rented out',
    rental_return: 'Returned',
    damage: 'Damaged',
};

const modes = [
    { value: 'receive', label: 'Receive', hint: 'Add stock that came in.' },
    {
        value: 'count',
        label: 'Count',
        hint: 'Set on-hand to what you counted.',
    },
    { value: 'damage', label: 'Damaged', hint: 'Remove broken or lost stock.' },
    {
        value: 'adjust',
        label: 'Adjust',
        hint: 'Add or remove by any amount (use a minus sign to remove).',
    },
] as const;

function AdjustDialog({
    row,
    locationId,
    onClose,
}: {
    row: StockRow;
    locationId: number;
    onClose: () => void;
}) {
    const form = useForm({
        product_id: row.id,
        location_id: locationId,
        mode: 'receive' as (typeof modes)[number]['value'],
        quantity: '',
        reorder_point: row.reorder_point ?? '',
        note: '',
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
                        {formatQuantity(row.on_hand)} {unitLabel(row.unit)} on
                        hand.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div
                        role="radiogroup"
                        aria-label="What happened"
                        className="grid grid-cols-4 gap-1 rounded-md bg-muted p-1"
                    >
                        {modes.map((m) => (
                            <button
                                key={m.value}
                                type="button"
                                role="radio"
                                aria-checked={form.data.mode === m.value}
                                onClick={() => form.setData('mode', m.value)}
                                className={cn(
                                    'rounded px-2 py-1.5 text-sm',
                                    form.data.mode === m.value
                                        ? 'bg-card font-medium shadow-xs'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {m.label}
                            </button>
                        ))}
                    </div>
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
                                placeholder="PO, supplier, reason"
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

export default function Inventory({
    locations,
    locationId,
    products,
    filters,
    movements,
}: {
    locations: { id: number; name: string; code: string }[];
    locationId: number | null;
    products: Paginated<StockRow>;
    filters: { search?: string };
    movements: Movement[];
}) {
    const [adjusting, setAdjusting] = useState<StockRow | null>(null);

    return (
        <>
            <Head title="Inventory" />
            <PageBody>
                <PageHeader
                    title="Inventory"
                    description="On-hand by yard. Every change is logged."
                    actions={
                        locations.length > 1 && (
                            <NativeSelect
                                aria-label="Yard"
                                className="w-auto"
                                value={locationId ?? ''}
                                onChange={(e) =>
                                    router.get(
                                        InventoryController.index.url(),
                                        { location_id: e.target.value },
                                        { preserveState: false },
                                    )
                                }
                            >
                                {locations.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        )
                    }
                />

                {locationId === null ? (
                    <p className="text-muted-foreground">
                        Add a yard first, then stock can be tracked there.
                    </p>
                ) : (
                    <div className="grid gap-8 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <div>
                            <div className="mb-4">
                                <SearchBox
                                    value={filters.search}
                                    placeholder="Search name, SKU or category"
                                    extra={{ location_id: locationId }}
                                />
                            </div>
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>Product</Th>
                                        <Th numeric>On hand</Th>
                                        <Th numeric>Reorder at</Th>
                                        <Th>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {products.data.length === 0 && (
                                        <EmptyRow colSpan={4}>
                                            No stocked products match.
                                        </EmptyRow>
                                    )}
                                    {products.data.map((row) => {
                                        const low =
                                            row.reorder_point !== null &&
                                            Number(row.on_hand) <=
                                                Number(row.reorder_point);

                                        return (
                                            <tr key={row.id}>
                                                <Td>
                                                    <div className="font-medium">
                                                        {row.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {row.sku}
                                                        {row.kind ===
                                                            'rental' &&
                                                            ', rental fleet'}
                                                    </div>
                                                </Td>
                                                <Td
                                                    numeric
                                                    className={cn(
                                                        low &&
                                                            'font-medium text-brick',
                                                        Number(row.on_hand) <
                                                            0 && 'text-brick',
                                                    )}
                                                >
                                                    {formatQuantity(
                                                        row.on_hand,
                                                    )}{' '}
                                                    <span className="text-muted-foreground">
                                                        {unitLabel(row.unit)}
                                                    </span>
                                                </Td>
                                                <Td
                                                    numeric
                                                    className="text-muted-foreground"
                                                >
                                                    {row.reorder_point
                                                        ? formatQuantity(
                                                              row.reorder_point,
                                                          )
                                                        : ''}
                                                </Td>
                                                <Td className="text-right">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setAdjusting(row)
                                                        }
                                                    >
                                                        Update
                                                    </Button>
                                                </Td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </Table>
                            <Pagination page={products} />
                        </div>

                        <Section title="Recent changes">
                            {movements.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing yet.
                                </p>
                            ) : (
                                <ol className="grid gap-3 text-sm">
                                    {movements.map((m) => (
                                        <li
                                            key={m.id}
                                            className="flex justify-between gap-3"
                                        >
                                            <div className="min-w-0">
                                                <div className="truncate">
                                                    {m.product.name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {movementLabels[m.type] ??
                                                        m.type}
                                                    {m.user
                                                        ? ` by ${m.user.name}`
                                                        : ''}
                                                    , {formatDate(m.created_at)}
                                                    {m.note
                                                        ? `. ${m.note}`
                                                        : ''}
                                                </div>
                                            </div>
                                            <span
                                                className={cn(
                                                    'whitespace-nowrap tabular-nums',
                                                    Number(m.quantity) < 0
                                                        ? 'text-brick'
                                                        : 'text-moss',
                                                )}
                                            >
                                                {Number(m.quantity) > 0
                                                    ? '+'
                                                    : ''}
                                                {formatQuantity(m.quantity)}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Section>
                    </div>
                )}

                {adjusting && locationId !== null && (
                    <AdjustDialog
                        row={adjusting}
                        locationId={locationId}
                        onClose={() => setAdjusting(null)}
                    />
                )}
            </PageBody>
        </>
    );
}

Inventory.layout = {
    breadcrumbs: [{ title: 'Inventory', href: InventoryController.index() }],
};
