import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PriceListController from '@/actions/App/Http/Controllers/PriceListController';
import PriceListItemController from '@/actions/App/Http/Controllers/PriceListItemController';
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
import type { PriceListItem, Product } from '@/types';

type Props = {
    priceList: {
        id: number;
        name: string;
        description: string | null;
        items: PriceListItem[];
    };
    products: (Pick<Product, 'id' | 'sku' | 'name' | 'unit' | 'price_cents'> & {
        units: {
            id: number;
            name: string;
            is_base: boolean;
            price_cents: number;
        }[];
    })[];
};

export default function EditPriceList({ priceList, products }: Props) {
    const details = useForm({
        name: priceList.name,
        description: priceList.description ?? '',
    });
    const item = useForm({
        product_id: '',
        product_unit_id: '',
        min_quantity: '1',
        price: '',
        starts_on: '',
        ends_on: '',
    });
    const selected = products.find(
        (p) => String(p.id) === item.data.product_id,
    );
    const selectedUnit =
        selected?.units.find(
            (u) => String(u.id) === item.data.product_unit_id,
        ) ?? selected?.units.find((u) => u.is_base);

    function saveDetails(event: FormEvent) {
        event.preventDefault();
        details.put(PriceListController.update.url(priceList.id), {
            preserveScroll: true,
        });
    }

    function addItem(event: FormEvent) {
        event.preventDefault();
        item.transform((d) => ({
            ...d,
            product_unit_id: d.product_unit_id || null,
            starts_on: d.starts_on || null,
            ends_on: d.ends_on || null,
        }));
        item.post(PriceListItemController.store.url(priceList.id), {
            preserveScroll: true,
            onSuccess: () => item.reset('price', 'min_quantity'),
        });
    }

    function remove(itemId: number) {
        router.delete(
            PriceListItemController.destroy.url({
                priceList: priceList.id,
                item: itemId,
            }),
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title={priceList.name} />
            <PageBody>
                <PageHeader
                    title={priceList.name}
                    description="Each price applies from its minimum quantity up. Products not listed sell at list price."
                />

                <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Section title="Prices">
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Product</Th>
                                    <Th numeric>From qty</Th>
                                    <Th numeric>List</Th>
                                    <Th numeric>This list</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {priceList.items.length === 0 && (
                                    <EmptyRow colSpan={5}>
                                        No prices yet. Add one on the right.
                                    </EmptyRow>
                                )}
                                {priceList.items.map((row) => {
                                    const list =
                                        row.unit?.price_cents ??
                                        row.product.price_cents;
                                    const saving = list - row.price_cents;

                                    return (
                                        <tr key={row.id}>
                                            <Td>
                                                <div className="font-medium">
                                                    {row.product.name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {row.product.sku}
                                                </div>
                                            </Td>
                                            <Td numeric>
                                                {formatQuantity(
                                                    row.min_quantity,
                                                )}{' '}
                                                {row.unit
                                                    ? row.unit.name.toLowerCase()
                                                    : unitLabel(
                                                          row.product.unit,
                                                      )}
                                                {(row.starts_on ||
                                                    row.ends_on) && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {row.starts_on
                                                            ? formatDate(
                                                                  row.starts_on,
                                                              )
                                                            : 'Now'}{' '}
                                                        –{' '}
                                                        {row.ends_on
                                                            ? formatDate(
                                                                  row.ends_on,
                                                              )
                                                            : 'ongoing'}
                                                    </div>
                                                )}
                                            </Td>
                                            <Td
                                                numeric
                                                className="text-muted-foreground"
                                            >
                                                {formatMoney(list)}
                                            </Td>
                                            <Td numeric>
                                                {formatMoney(row.price_cents)}
                                                {saving > 0 && (
                                                    <div className="text-xs text-success">
                                                        {Math.round(
                                                            (saving / list) *
                                                                100,
                                                        )}
                                                        % off
                                                    </div>
                                                )}
                                            </Td>
                                            <Td className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        remove(row.id)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </Section>

                    <div className="grid content-start gap-8">
                        <Section title="Add or change a price">
                            <form onSubmit={addItem} className="grid gap-4">
                                <Field
                                    label="Product"
                                    htmlFor="product_id"
                                    error={item.errors.product_id}
                                >
                                    <NativeSelect
                                        id="product_id"
                                        value={item.data.product_id}
                                        onChange={(e) =>
                                            item.setData((d) => ({
                                                ...d,
                                                product_id: e.target.value,
                                                product_unit_id: '',
                                            }))
                                        }
                                        required
                                    >
                                        <option value="">
                                            Choose a product
                                        </option>
                                        {products.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name} ({p.sku})
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                {selected && selected.units.length > 1 && (
                                    <Field
                                        label="Unit"
                                        htmlFor="product_unit_id"
                                        error={item.errors.product_unit_id}
                                    >
                                        <NativeSelect
                                            id="product_unit_id"
                                            value={item.data.product_unit_id}
                                            onChange={(e) =>
                                                item.setData(
                                                    'product_unit_id',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {selected.units.map((u) => (
                                                <option
                                                    key={u.id}
                                                    value={
                                                        u.is_base ? '' : u.id
                                                    }
                                                >
                                                    {u.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                )}
                                <div className="grid grid-cols-2 gap-4">
                                    <Field
                                        label="From quantity"
                                        htmlFor="min_quantity"
                                        error={item.errors.min_quantity}
                                    >
                                        <Input
                                            id="min_quantity"
                                            inputMode="decimal"
                                            value={item.data.min_quantity}
                                            onChange={(e) =>
                                                item.setData(
                                                    'min_quantity',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Price"
                                        htmlFor="price"
                                        error={item.errors.price}
                                        hint={
                                            selected
                                                ? `Retail ${formatMoney(selectedUnit?.price_cents ?? selected.price_cents)} / ${selectedUnit && !selectedUnit.is_base ? selectedUnit.name.toLowerCase() : unitLabel(selected.unit)}`
                                                : undefined
                                        }
                                    >
                                        <Input
                                            id="price"
                                            inputMode="decimal"
                                            value={item.data.price}
                                            onChange={(e) =>
                                                item.setData(
                                                    'price',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </Field>
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <Field
                                        label="From"
                                        htmlFor="starts_on"
                                        error={item.errors.starts_on}
                                        hint="Blank for now"
                                    >
                                        <Input
                                            id="starts_on"
                                            type="date"
                                            value={item.data.starts_on}
                                            onChange={(e) =>
                                                item.setData(
                                                    'starts_on',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Until"
                                        htmlFor="ends_on"
                                        error={item.errors.ends_on}
                                        hint="Blank for ongoing"
                                    >
                                        <Input
                                            id="ends_on"
                                            type="date"
                                            value={item.data.ends_on}
                                            onChange={(e) =>
                                                item.setData(
                                                    'ends_on',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <div>
                                    <Button
                                        type="submit"
                                        disabled={item.processing}
                                    >
                                        Save price
                                    </Button>
                                </div>
                            </form>
                        </Section>

                        <Section title="Details">
                            <form onSubmit={saveDetails} className="grid gap-4">
                                <Field
                                    label="Name"
                                    htmlFor="name"
                                    error={details.errors.name}
                                >
                                    <Input
                                        id="name"
                                        value={details.data.name}
                                        onChange={(e) =>
                                            details.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Description"
                                    htmlFor="description"
                                    error={details.errors.description}
                                >
                                    <Input
                                        id="description"
                                        value={details.data.description}
                                        onChange={(e) =>
                                            details.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={details.processing}
                                    >
                                        Save details
                                    </Button>
                                </div>
                            </form>
                        </Section>
                    </div>
                </div>
            </PageBody>
        </>
    );
}

EditPriceList.layout = {
    breadcrumbs: [{ title: 'Price lists', href: PriceListController.index() }],
};
