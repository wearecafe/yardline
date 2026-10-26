import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PriceListController from '@/actions/App/Http/Controllers/PriceListController';
import PriceListItemController from '@/actions/App/Http/Controllers/PriceListItemController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney, formatQuantity, unitLabel } from '@/lib/format';
import type { PriceListItem, Product } from '@/types';

type Props = {
    priceList: {
        id: number;
        name: string;
        description: string | null;
        items: PriceListItem[];
    };
    products: Pick<Product, 'id' | 'sku' | 'name' | 'unit' | 'price_cents'>[];
};

export default function EditPriceList({ priceList, products }: Props) {
    const details = useForm({
        name: priceList.name,
        description: priceList.description ?? '',
    });
    const item = useForm({ product_id: '', min_quantity: '1', price: '' });
    const selected = products.find(
        (p) => String(p.id) === item.data.product_id,
    );

    function saveDetails(event: FormEvent) {
        event.preventDefault();
        details.put(PriceListController.update.url(priceList.id), {
            preserveScroll: true,
        });
    }

    function addItem(event: FormEvent) {
        event.preventDefault();
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
                                    const saving =
                                        row.product.price_cents -
                                        row.price_cents;

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
                                                {unitLabel(row.product.unit)}
                                            </Td>
                                            <Td
                                                numeric
                                                className="text-muted-foreground"
                                            >
                                                {formatMoney(
                                                    row.product.price_cents,
                                                )}
                                            </Td>
                                            <Td numeric>
                                                {formatMoney(row.price_cents)}
                                                {saving > 0 && (
                                                    <div className="text-xs text-moss">
                                                        {Math.round(
                                                            (saving /
                                                                row.product
                                                                    .price_cents) *
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
                                            item.setData(
                                                'product_id',
                                                e.target.value,
                                            )
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
                                                ? `List ${formatMoney(selected.price_cents)} / ${unitLabel(selected.unit)}`
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
