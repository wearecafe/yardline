import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import ProductUnitController from '@/actions/App/Http/Controllers/ProductUnitController';
import { Table, Td, Th } from '@/components/yard/data-table';
import { NativeSelect } from '@/components/yard/field';
import { Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { centsToInput, formatQuantity } from '@/lib/format';

export type EditorUnit = {
    id: number;
    name: string;
    factor: string;
    is_base: boolean;
    price_cents: number;
    variant_title: string | null;
    measured_by: string | null;
    increment: string | null;
    min_quantity: string | null;
    deposit_cents: number;
    tiers: Record<string, number | null>;
};

type Row = {
    id: number | null;
    name: string;
    factor: string;
    is_base: boolean;
    price: string;
    variant_title: string;
    measured_by: string;
    increment: string;
    min_quantity: string;
    deposit: string;
    tiers: Record<string, string>;
};

const trim = (value: string | null) =>
    value === null ? '' : String(Number(value));

/**
 * Selling units and the price grid from the product editor mockup: one
 * product sold many ways, every sale converting back to the base unit,
 * and a retail price plus a price per price list for each unit.
 */
export function ProductUnitsEditor({
    productId,
    baseUnit,
    units,
    priceLists,
    measures,
    costCents,
}: {
    productId: number;
    baseUnit: string;
    units: EditorUnit[];
    priceLists: { id: number; name: string }[];
    measures: { value: string; label: string }[];
    costCents: number | null;
}) {
    const form = useForm<{ units: Row[] }>({
        units: units.map((u) => ({
            id: u.id,
            name: u.name,
            factor: trim(u.factor),
            is_base: u.is_base,
            price: centsToInput(u.price_cents),
            variant_title: u.variant_title ?? '',
            measured_by: u.measured_by ?? '',
            increment: u.increment ? trim(u.increment) : '',
            min_quantity: u.min_quantity ? trim(u.min_quantity) : '',
            deposit: u.deposit_cents ? centsToInput(u.deposit_cents) : '',
            tiers: Object.fromEntries(
                priceLists.map((l) => [
                    String(l.id),
                    centsToInput(u.tiers[String(l.id)] ?? null),
                ]),
            ),
        })),
    });
    const rows = form.data.units;
    const marginList = priceLists[0];

    function update(index: number, patch: Partial<Row>) {
        form.setData(
            'units',
            rows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );
    }

    function margin(row: Row): string {
        const price = Number(
            marginList
                ? row.tiers[String(marginList.id)] || row.price
                : row.price,
        );
        const cost =
            costCents !== null
                ? (costCents / 100) * Number(row.factor || 0)
                : null;

        if (!price || cost === null) {
            return '—';
        }

        return `${Math.round(((price - cost) / price) * 100)}%`;
    }

    const errors = form.errors as Record<string, string>;

    return (
        <form
            className="grid gap-5"
            onSubmit={(e) => {
                e.preventDefault();
                form.put(ProductUnitController.sync.url(productId), {
                    preserveScroll: true,
                });
            }}
        >
            <Section
                title="Selling units"
                description={`One product, many ways to sell it. Stock is counted in ${baseUnit.toLowerCase()}s; every sale converts back to it.`}
                flush
            >
                <Table minWidth={760}>
                    <thead>
                        <tr>
                            <Th>Unit</Th>
                            <Th>Equals</Th>
                            <Th>Shopify variant</Th>
                            <Th>How it's measured</Th>
                            <Th>Sold in steps of</Th>
                            <Th>Deposit</Th>
                            <Th className="w-10">
                                <span className="sr-only">Remove</span>
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, i) => (
                            <tr key={row.id ?? `new-${i}`}>
                                <Td>
                                    <Input
                                        aria-label="Unit name"
                                        value={row.name}
                                        onChange={(e) =>
                                            update(i, { name: e.target.value })
                                        }
                                        className="min-w-36"
                                    />
                                    {row.is_base && (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Base unit
                                        </div>
                                    )}
                                </Td>
                                <Td>
                                    {row.is_base ? (
                                        <span className="figure">
                                            1 {baseUnit.toLowerCase()}
                                        </span>
                                    ) : (
                                        <div className="flex items-center gap-1.5">
                                            <Input
                                                aria-label={`${row.name} equals how many ${baseUnit}`}
                                                inputMode="decimal"
                                                value={row.factor}
                                                onChange={(e) =>
                                                    update(i, {
                                                        factor: e.target.value,
                                                    })
                                                }
                                                className="figure w-24"
                                            />
                                            <span className="text-muted-foreground">
                                                {baseUnit.toLowerCase()}
                                            </span>
                                        </div>
                                    )}
                                </Td>
                                <Td>
                                    <Input
                                        aria-label="Shopify variant"
                                        value={row.variant_title}
                                        placeholder={`Per ${row.name.toLowerCase()}`}
                                        onChange={(e) =>
                                            update(i, {
                                                variant_title: e.target.value,
                                            })
                                        }
                                        className="min-w-32"
                                    />
                                </Td>
                                <Td>
                                    <NativeSelect
                                        aria-label="How it's measured"
                                        value={row.measured_by}
                                        onChange={(e) =>
                                            update(i, {
                                                measured_by: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="">—</option>
                                        {measures.map((m) => (
                                            <option
                                                key={m.value}
                                                value={m.value}
                                            >
                                                {m.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Td>
                                <Td>
                                    <Input
                                        aria-label="Sold in steps of"
                                        inputMode="decimal"
                                        placeholder="Any"
                                        value={row.increment}
                                        onChange={(e) =>
                                            update(i, {
                                                increment: e.target.value,
                                            })
                                        }
                                        className="figure w-20"
                                    />
                                </Td>
                                <Td>
                                    <Input
                                        aria-label="Container deposit"
                                        inputMode="decimal"
                                        placeholder="0.00"
                                        value={row.deposit}
                                        onChange={(e) =>
                                            update(i, {
                                                deposit: e.target.value,
                                            })
                                        }
                                        className="w-24"
                                    />
                                </Td>
                                <Td>
                                    {!row.is_base && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${row.name}`}
                                            onClick={() =>
                                                form.setData(
                                                    'units',
                                                    rows.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <X />
                                        </Button>
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
                <div className="border-t border-border px-4 py-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            form.setData('units', [
                                ...rows,
                                {
                                    id: null,
                                    name: '',
                                    factor: '1',
                                    is_base: false,
                                    price: '',
                                    variant_title: '',
                                    measured_by: '',
                                    increment: '',
                                    min_quantity: '',
                                    deposit: '',
                                    tiers: {},
                                },
                            ])
                        }
                    >
                        <Plus /> Add selling unit
                    </Button>
                </div>
            </Section>

            <Section
                title="Price tiers"
                description={
                    priceLists.length > 0
                        ? 'Retail is the walk-in price. Each price list is what its accounts pay; leave one blank to convert from the base unit.'
                        : 'Add price lists to set contractor and wholesale prices.'
                }
                flush
            >
                <Table minWidth={560}>
                    <thead>
                        <tr>
                            <Th>Unit</Th>
                            <Th numeric>Retail</Th>
                            {priceLists.map((list) => (
                                <Th key={list.id} numeric>
                                    {list.name}
                                </Th>
                            ))}
                            <Th numeric>
                                Margin
                                {marginList
                                    ? ` at ${marginList.name.toLowerCase()}`
                                    : ''}
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, i) => (
                            <tr key={row.id ?? `new-${i}`}>
                                <Td className="font-medium">
                                    {row.name || 'New unit'}
                                </Td>
                                <Td numeric>
                                    <Input
                                        aria-label={`${row.name} retail price`}
                                        inputMode="decimal"
                                        value={row.price}
                                        onChange={(e) =>
                                            update(i, { price: e.target.value })
                                        }
                                        className="figure ml-auto w-28 text-right"
                                        required
                                    />
                                </Td>
                                {priceLists.map((list) => (
                                    <Td key={list.id} numeric>
                                        <Input
                                            aria-label={`${row.name} ${list.name} price`}
                                            inputMode="decimal"
                                            value={
                                                row.tiers[String(list.id)] ?? ''
                                            }
                                            onChange={(e) =>
                                                update(i, {
                                                    tiers: {
                                                        ...row.tiers,
                                                        [String(list.id)]:
                                                            e.target.value,
                                                    },
                                                })
                                            }
                                            className="figure ml-auto w-28 text-right"
                                        />
                                    </Td>
                                ))}
                                <Td numeric className="text-subtle">
                                    {margin(row)}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                    <div className="text-sm text-muted-foreground">
                        {rows
                            .filter((r) => !r.is_base && Number(r.factor) > 0)
                            .map(
                                (r) =>
                                    `1 ${r.name.toLowerCase()} = ${formatQuantity(r.factor)} ${baseUnit.toLowerCase()}`,
                            )
                            .join(' · ')}
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Save units and prices
                    </Button>
                </div>
                {Object.values(errors)
                    .slice(0, 3)
                    .map((message) => (
                        <div key={message} className="px-4 pb-3">
                            <InputError message={message} />
                        </div>
                    ))}
            </Section>
        </form>
    );
}
