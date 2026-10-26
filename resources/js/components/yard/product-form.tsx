import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { centsToInput } from '@/lib/format';
import type { Option, Product } from '@/types';

export function ProductForm({
    product,
    units,
    categories,
}: {
    product?: Product;
    units: Option[];
    categories: string[];
}) {
    const form = useForm({
        sku: product?.sku ?? '',
        name: product?.name ?? '',
        description: product?.description ?? '',
        category: product?.category ?? '',
        kind: product?.kind ?? 'sale',
        unit: product?.unit ?? 'each',
        price: centsToInput(product?.price_cents),
        cost: centsToInput(product?.cost_cents),
        rental_period: product?.rental_period ?? 'day',
        deposit: centsToInput(product?.deposit_cents),
        tax_code: product?.tax_code ?? '',
        track_inventory: product?.track_inventory ?? true,
        is_active: product?.is_active ?? true,
        rate_hour: centsToInput(product?.rate_hour_cents),
        rate_half_day: centsToInput(product?.rate_half_day_cents),
        rate_weekend: centsToInput(product?.rate_weekend_cents),
        rate_week: centsToInput(product?.rate_week_cents),
        rate_month: centsToInput(product?.rate_month_cents),
        minimum_charge: centsToInput(product?.minimum_charge_cents || null),
        fuel_charge: centsToInput(product?.fuel_charge_cents || null),
        cleaning_fee: centsToInput(product?.cleaning_fee_cents || null),
        service_interval_hours: product?.service_interval_hours
            ? String(product.service_interval_hours)
            : '',
        service_interval_days: product?.service_interval_days
            ? String(product.service_interval_days)
            : '',
        use_case: product?.use_case ?? '',
        bookable_online: product?.bookable_online ?? false,
    });

    type MoneyKey =
        | 'rate_hour'
        | 'rate_half_day'
        | 'rate_weekend'
        | 'rate_week'
        | 'rate_month'
        | 'minimum_charge'
        | 'fuel_charge'
        | 'cleaning_fee'
        | 'service_interval_hours'
        | 'service_interval_days';

    const moneyField = (key: MoneyKey, label: string, hint?: string) => (
        <Field
            key={key}
            label={label}
            htmlFor={key}
            error={form.errors[key]}
            hint={hint}
        >
            <Input
                id={key}
                inputMode="decimal"
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </Field>
    );

    const isRental = form.data.kind === 'rental';

    function submit(event: FormEvent) {
        event.preventDefault();

        if (product) {
            form.put(ProductController.update.url(product.id), {
                preserveScroll: true,
            });
        } else {
            form.post(ProductController.store.url());
        }
    }

    return (
        <form onSubmit={submit} className="grid max-w-3xl gap-8">
            <Section title="What it is">
                <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                    <Field label="SKU" htmlFor="sku" error={form.errors.sku}>
                        <Input
                            id="sku"
                            value={form.data.sku}
                            onChange={(e) =>
                                form.setData('sku', e.target.value)
                            }
                            required
                            autoFocus={!product}
                        />
                    </Field>
                    <Field label="Name" htmlFor="name" error={form.errors.name}>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                    </Field>
                </div>
                <div className="mt-4 grid gap-4 sm:grid-cols-3">
                    <Field
                        label="Sold as"
                        htmlFor="kind"
                        error={form.errors.kind}
                    >
                        <NativeSelect
                            id="kind"
                            value={form.data.kind}
                            onChange={(e) =>
                                form.setData(
                                    'kind',
                                    e.target.value as Product['kind'],
                                )
                            }
                        >
                            <option value="sale">Material for sale</option>
                            <option value="rental">Rental equipment</option>
                        </NativeSelect>
                    </Field>
                    <Field
                        label="Category"
                        htmlFor="category"
                        error={form.errors.category}
                    >
                        <Input
                            id="category"
                            list="product-categories"
                            value={form.data.category}
                            onChange={(e) =>
                                form.setData('category', e.target.value)
                            }
                        />
                        <datalist id="product-categories">
                            {categories.map((category) => (
                                <option key={category} value={category} />
                            ))}
                        </datalist>
                    </Field>
                    <Field label="Unit" htmlFor="unit" error={form.errors.unit}>
                        <NativeSelect
                            id="unit"
                            value={form.data.unit}
                            onChange={(e) =>
                                form.setData('unit', e.target.value)
                            }
                        >
                            {units.map((unit) => (
                                <option key={unit.value} value={unit.value}>
                                    {unit.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                </div>
                <Field
                    label="Description"
                    htmlFor="description"
                    error={form.errors.description}
                    className="mt-4"
                >
                    <textarea
                        id="description"
                        rows={3}
                        className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm"
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                </Field>
            </Section>

            <Section title="Price">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        label={isRental ? 'Day rate' : 'List price'}
                        htmlFor="price"
                        error={form.errors.price}
                        hint={
                            isRental
                                ? undefined
                                : 'Walk-in price. Price lists can set contractor pricing.'
                        }
                    >
                        <Input
                            id="price"
                            inputMode="decimal"
                            value={form.data.price}
                            onChange={(e) =>
                                form.setData('price', e.target.value)
                            }
                            required
                        />
                    </Field>
                    {isRental ? (
                        <>
                            <Field
                                label="Deposit"
                                htmlFor="deposit"
                                error={form.errors.deposit}
                                hint="Taken up front, per unit."
                            >
                                <Input
                                    id="deposit"
                                    inputMode="decimal"
                                    value={form.data.deposit}
                                    onChange={(e) =>
                                        form.setData('deposit', e.target.value)
                                    }
                                />
                            </Field>
                        </>
                    ) : (
                        <Field
                            label="Cost"
                            htmlFor="cost"
                            error={form.errors.cost}
                            hint="Optional. Used for margin."
                        >
                            <Input
                                id="cost"
                                inputMode="decimal"
                                value={form.data.cost}
                                onChange={(e) =>
                                    form.setData('cost', e.target.value)
                                }
                            />
                        </Field>
                    )}
                </div>
                <Field
                    label="Stripe tax code"
                    htmlFor="tax_code"
                    error={form.errors.tax_code}
                    hint="Leave blank for the account default (general goods)."
                    className="mt-4 max-w-xs"
                >
                    <Input
                        id="tax_code"
                        value={form.data.tax_code}
                        placeholder="txcd_99999999"
                        onChange={(e) =>
                            form.setData('tax_code', e.target.value)
                        }
                    />
                </Field>
            </Section>

            {isRental && (
                <Section title="Rental rates and fleet">
                    <p className="mb-4 text-sm text-muted-foreground">
                        Leave a rate blank if you don't offer it. Rentals are
                        priced at the cheapest mix of the rates you set, so a
                        week beats seven days.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {moneyField('rate_hour', 'Hourly')}
                        {moneyField(
                            'rate_half_day',
                            'Half day',
                            'Up to 4 hours.',
                        )}
                        {moneyField(
                            'rate_weekend',
                            'Weekend',
                            'Saturday pickup to Monday morning.',
                        )}
                        {moneyField('rate_week', 'Week')}
                        {moneyField('rate_month', 'Month', '28 days.')}
                        {moneyField('minimum_charge', 'Minimum charge')}
                        {moneyField(
                            'fuel_charge',
                            'Refuel charge',
                            'If it comes back low.',
                        )}
                        {moneyField(
                            'cleaning_fee',
                            'Cleaning fee',
                            'If it comes back dirty.',
                        )}
                    </div>
                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                        {moneyField(
                            'service_interval_hours',
                            'Service every (hours)',
                        )}
                        {moneyField('service_interval_days', 'or every (days)')}
                    </div>
                    <Field
                        label="What it's for"
                        htmlFor="use_case"
                        error={form.errors.use_case}
                        hint="One line on the public booking page."
                        className="mt-4"
                    >
                        <Input
                            id="use_case"
                            value={form.data.use_case}
                            placeholder="Compacting base for patios and walkways"
                            onChange={(e) =>
                                form.setData('use_case', e.target.value)
                            }
                        />
                    </Field>
                    <div className="mt-4">
                        <Checkbox
                            id="bookable_online"
                            label="Bookable online"
                            hint="Show it on your public booking page."
                            checked={form.data.bookable_online}
                            onChange={(checked) =>
                                form.setData('bookable_online', checked)
                            }
                        />
                    </div>
                </Section>
            )}

            <Section title="Stock and selling">
                <div className="grid gap-4">
                    <Checkbox
                        id="track_inventory"
                        label="Track stock"
                        hint="Count on-hand at each yard and draw it down when orders are released."
                        checked={form.data.track_inventory}
                        onChange={(checked) =>
                            form.setData('track_inventory', checked)
                        }
                    />
                    <Checkbox
                        id="is_active"
                        label="Active"
                        hint="Inactive products can't be added to new orders."
                        checked={form.data.is_active}
                        onChange={(checked) =>
                            form.setData('is_active', checked)
                        }
                    />
                </div>
            </Section>

            <div>
                <Button type="submit" disabled={form.processing}>
                    {product ? 'Save product' : 'Add product'}
                </Button>
            </div>
        </form>
    );
}
