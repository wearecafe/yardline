import { Head, router, useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PriceListCategoryRuleController from '@/actions/App/Http/Controllers/PriceListCategoryRuleController';
import PriceListController from '@/actions/App/Http/Controllers/PriceListController';
import PriceListItemController from '@/actions/App/Http/Controllers/PriceListItemController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';
import type { CategoryRule, PriceListItem, Product } from '@/types';

type Props = {
    priceList: {
        id: number;
        name: string;
        description: string | null;
        category_rules_override: boolean | null;
        stack_volume_breaks: boolean;
        items: PriceListItem[];
        category_rules: CategoryRule[];
    };
    tenantOverride: boolean;
    products: (Pick<
        Product,
        'id' | 'sku' | 'name' | 'unit' | 'price_cents' | 'price_list_exempt'
    > & {
        units: {
            id: number;
            name: string;
            is_base: boolean;
            price_cents: number;
        }[];
    })[];
    categories: { name: string; products: number; exempt: number }[];
};

type RuleForm = {
    category: string;
    discount_type: 'percent' | 'amount';
    discount: string;
    starts_on: string;
    ends_on: string;
};

function overrideValue(value: boolean | null): 'default' | 'on' | 'off' {
    if (value === null) {
        return 'default';
    }

    return value ? 'on' : 'off';
}

export default function EditPriceList({
    priceList,
    tenantOverride,
    products,
    categories,
}: Props) {
    const details = useForm({
        name: priceList.name,
        description: priceList.description ?? '',
        category_rules_override: overrideValue(
            priceList.category_rules_override,
        ),
        stack_volume_breaks: priceList.stack_volume_breaks,
    });
    const rule = useForm<RuleForm>({
        category: '',
        discount_type: 'percent',
        discount: '',
        starts_on: '',
        ends_on: '',
    });
    const categoryWins = priceList.category_rules_override ?? tenantOverride;
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

    function addRule(event: FormEvent) {
        event.preventDefault();
        rule.transform((d) => ({
            ...d,
            starts_on: d.starts_on || null,
            ends_on: d.ends_on || null,
        }));
        rule.post(PriceListCategoryRuleController.store.url(priceList.id), {
            preserveScroll: true,
            onSuccess: () => rule.reset(),
        });
    }

    function removeRule(ruleId: number) {
        router.delete(
            PriceListCategoryRuleController.destroy.url({
                priceList: priceList.id,
                rule: ruleId,
            }),
            { preserveScroll: true },
        );
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
                    description={`Each price applies from its minimum quantity up. Category discounts cover products without a price here${categoryWins ? ', and replace the prices here for their category' : ''}. Products exempt from price lists always sell at list price.`}
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
                                                    {row.product
                                                        .price_list_exempt &&
                                                        ', exempt from price lists: not applied'}
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
                        <CategoryRules
                            priceList={priceList}
                            categories={categories}
                            rule={rule}
                            onAdd={addRule}
                            onRemove={removeRule}
                        />
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
                                                {p.price_list_exempt
                                                    ? ' — exempt'
                                                    : ''}
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

                        <Section title="Details and rules">
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
                                <Field
                                    label="When a product has a price here and a category discount"
                                    htmlFor="category_rules_override"
                                    error={
                                        details.errors.category_rules_override
                                    }
                                >
                                    <NativeSelect
                                        id="category_rules_override"
                                        value={
                                            details.data.category_rules_override
                                        }
                                        onChange={(e) =>
                                            details.setData(
                                                'category_rules_override',
                                                e.target.value as
                                                    | 'default'
                                                    | 'on'
                                                    | 'off',
                                            )
                                        }
                                    >
                                        <option value="default">
                                            Business default (
                                            {tenantOverride
                                                ? 'category discount wins'
                                                : 'product price wins'}
                                            )
                                        </option>
                                        <option value="off">
                                            Product price wins
                                        </option>
                                        <option value="on">
                                            Category discount wins
                                        </option>
                                    </NativeSelect>
                                </Field>
                                <Checkbox
                                    id="stack_volume_breaks"
                                    label="Stack category discounts on volume breaks"
                                    hint="A price for more than 1 gets the category discount taken off it as well."
                                    checked={details.data.stack_volume_breaks}
                                    onChange={(checked) =>
                                        details.setData(
                                            'stack_volume_breaks',
                                            checked,
                                        )
                                    }
                                />
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

function describeRule(rule: CategoryRule): string {
    return rule.discount_type === 'percent'
        ? `${Number(rule.discount_percent)}% off`
        : `${formatMoney(rule.discount_cents ?? 0)} off per base unit`;
}

function CategoryRules({
    priceList,
    categories,
    rule,
    onAdd,
    onRemove,
}: {
    priceList: Props['priceList'];
    categories: Props['categories'];
    rule: InertiaFormProps<RuleForm>;
    onAdd: (event: FormEvent) => void;
    onRemove: (ruleId: number) => void;
}) {
    const counts = new Map(
        categories.map((c) => [c.name.trim().toLowerCase(), c]),
    );

    return (
        <Section title="Category discounts">
            {priceList.category_rules.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    None yet. Take $ or % off everything in a category.
                </p>
            ) : (
                <ul className="grid gap-2 text-sm">
                    {priceList.category_rules.map((r) => {
                        const c = counts.get(r.category.trim().toLowerCase());

                        return (
                            <li
                                key={r.id}
                                className="flex items-start justify-between gap-3"
                            >
                                <div>
                                    <div className="font-medium">
                                        {r.category}: {describeRule(r)}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {c
                                            ? `${c.products} product${c.products === 1 ? '' : 's'}${c.exempt ? `, ${c.exempt} exempt` : ''}`
                                            : 'No active products'}
                                        {(r.starts_on || r.ends_on) &&
                                            `. ${r.starts_on ? formatDate(r.starts_on) : 'Now'} – ${r.ends_on ? formatDate(r.ends_on) : 'ongoing'}`}
                                    </div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onRemove(r.id)}
                                >
                                    Remove
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <form onSubmit={onAdd} className="mt-4 grid gap-4">
                <Field
                    label="Category"
                    htmlFor="rule_category"
                    error={rule.errors.category}
                >
                    <NativeSelect
                        id="rule_category"
                        value={rule.data.category}
                        onChange={(e) =>
                            rule.setData('category', e.target.value)
                        }
                        required
                    >
                        <option value="">Choose a category</option>
                        {categories.map((c) => (
                            <option key={c.name} value={c.name}>
                                {c.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <div className="grid grid-cols-2 gap-4">
                    <Field
                        label="Discount"
                        htmlFor="discount_type"
                        error={rule.errors.discount_type}
                    >
                        <NativeSelect
                            id="discount_type"
                            value={rule.data.discount_type}
                            onChange={(e) =>
                                rule.setData(
                                    'discount_type',
                                    e.target.value as 'percent' | 'amount',
                                )
                            }
                        >
                            <option value="percent">% off</option>
                            <option value="amount">$ off</option>
                        </NativeSelect>
                    </Field>
                    <Field
                        label={
                            rule.data.discount_type === 'percent'
                                ? 'Percent'
                                : 'Amount'
                        }
                        htmlFor="discount"
                        error={rule.errors.discount}
                        hint={
                            rule.data.discount_type === 'amount'
                                ? 'Per base unit. Scales up for pallets and other units.'
                                : undefined
                        }
                    >
                        <Input
                            id="discount"
                            inputMode="decimal"
                            value={rule.data.discount}
                            onChange={(e) =>
                                rule.setData('discount', e.target.value)
                            }
                            required
                        />
                    </Field>
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <Field
                        label="From"
                        htmlFor="rule_starts_on"
                        error={rule.errors.starts_on}
                        hint="Blank for now"
                    >
                        <Input
                            id="rule_starts_on"
                            type="date"
                            value={rule.data.starts_on}
                            onChange={(e) =>
                                rule.setData('starts_on', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Until"
                        htmlFor="rule_ends_on"
                        error={rule.errors.ends_on}
                        hint="Blank for ongoing"
                    >
                        <Input
                            id="rule_ends_on"
                            type="date"
                            value={rule.data.ends_on}
                            onChange={(e) =>
                                rule.setData('ends_on', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <div>
                    <Button type="submit" disabled={rule.processing}>
                        Save category discount
                    </Button>
                </div>
            </form>
        </Section>
    );
}
