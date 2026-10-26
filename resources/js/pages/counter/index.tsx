import { Head, router, useForm } from '@inertiajs/react';
import { Minus, Plus, Search, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import CounterController from '@/actions/App/Http/Controllers/CounterController';
import InputError from '@/components/input-error';
import { Combobox } from '@/components/yard/combobox';
import { NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney, formatQuantity } from '@/lib/format';
import { getJson } from '@/lib/json';
import { cn } from '@/lib/utils';
import type { Option } from '@/types';

type Unit = {
    id: number;
    name: string;
    is_base: boolean;
    price_cents: number;
    increment: string | null;
};
type Product = {
    id: number;
    sku: string;
    name: string;
    category: string;
    on_hand: string | null;
    units: Unit[];
};
type CartLine = {
    key: string;
    product_id: number;
    product_unit_id: number;
    quantity: string;
};

type Props = {
    yard: { id: number; name: string };
    yards: { id: number; name: string }[];
    products: Product[];
    organizations: {
        id: number;
        name: string;
        account_number: string;
        credit_hold: boolean;
    }[];
    customers: {
        id: number;
        organization_id: number | null;
        first_name: string;
        last_name: string | null;
        phone: string | null;
    }[];
    tenders: Option[];
    taxRate: number | null;
};

/**
 * The counter sale screen: tap items in, pick the customer, take payment,
 * print the receipt. The sale is confirmed, paid and handed over at once.
 */
export default function Counter({
    yard,
    yards,
    products,
    organizations,
    customers,
    tenders,
    taxRate,
}: Props) {
    const categories = useMemo(
        () => ['All', ...new Set(products.map((p) => p.category))],
        [products],
    );
    const [category, setCategory] = useState('All');
    const [search, setSearch] = useState('');
    const [prices, setPrices] = useState<
        Record<number, { price_cents: number; source: string }>
    >({});

    const form = useForm<{
        location_id: number;
        organization_id: number | null;
        customer_id: number | null;
        po_number: string;
        lines: CartLine[];
        settlement: 'prepaid' | 'account';
        tender: string;
        tendered: string;
        reference: string;
    }>({
        location_id: yard.id,
        organization_id: null,
        customer_id: null,
        po_number: '',
        lines: [],
        settlement: 'prepaid',
        tender: 'card_terminal',
        tendered: '',
        reference: '',
    });

    const byId = useMemo(
        () => new Map(products.map((p) => [p.id, p])),
        [products],
    );
    const shown = products.filter(
        (p) =>
            (category === 'All' || p.category === category) &&
            (!search ||
                `${p.name} ${p.sku}`
                    .toLowerCase()
                    .includes(search.toLowerCase())),
    );
    const people = form.data.organization_id
        ? customers.filter(
              (c) => c.organization_id === form.data.organization_id,
          )
        : customers;
    const org = organizations.find((o) => o.id === form.data.organization_id);

    // Account prices for the cart.
    useEffect(() => {
        if (!form.data.organization_id || form.data.lines.length === 0) {
            setPrices({});

            return;
        }

        const controller = new AbortController();
        getJson<Record<number, { price_cents: number; source: string }>>(
            CounterController.prices.url(),
            {
                organization_id: form.data.organization_id,
                lines: form.data.lines.map((l) => ({
                    product_id: l.product_id,
                    product_unit_id: l.product_unit_id,
                    quantity: l.quantity || 1,
                })),
            },
            controller.signal,
        )
            .then(setPrices)
            .catch(() => {});

        return () => controller.abort();
    }, [form.data.organization_id, form.data.lines]);

    function unitOf(line: CartLine): Unit | undefined {
        return byId
            .get(line.product_id)
            ?.units.find((u) => u.id === line.product_unit_id);
    }

    function priceOf(line: CartLine, i: number): number {
        return prices[i]?.price_cents ?? unitOf(line)?.price_cents ?? 0;
    }

    function add(product: Product) {
        const base = product.units.find((u) => u.is_base) ?? product.units[0];
        const existing = form.data.lines.findIndex(
            (l) => l.product_id === product.id && l.product_unit_id === base.id,
        );

        if (existing >= 0) {
            setQty(
                existing,
                String(Number(form.data.lines[existing].quantity) + 1),
            );

            return;
        }

        form.setData('lines', [
            ...form.data.lines,
            {
                key: `${product.id}-${Date.now()}`,
                product_id: product.id,
                product_unit_id: base.id,
                quantity: '1',
            },
        ]);
    }

    function setQty(i: number, quantity: string) {
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, quantity } : l)),
        );
    }

    const subtotal = form.data.lines.reduce(
        (sum, l, i) =>
            sum + Math.round(Number(l.quantity || 0) * priceOf(l, i)),
        0,
    );
    const tax = taxRate !== null ? Math.round(subtotal * taxRate) : null;
    const total = subtotal + (tax ?? 0);
    const change =
        form.data.tender === 'cash' && form.data.tendered
            ? Math.round(Number(form.data.tendered) * 100) - total
            : null;
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head title="Counter" />
            <PageBody>
                <PageHeader
                    title="Counter"
                    description="Ring up a sale, take payment and hand it over."
                    actions={
                        <NativeSelect
                            aria-label="Yard"
                            value={String(form.data.location_id)}
                            onChange={(e) =>
                                router.get(CounterController.show.url(), {
                                    yard: e.target.value,
                                })
                            }
                            className="w-auto"
                        >
                            {yards.map((y) => (
                                <option key={y.id} value={y.id}>
                                    {y.name}
                                </option>
                            ))}
                        </NativeSelect>
                    }
                />
                <div className="flex flex-wrap items-start gap-5">
                    <section className="flex min-w-0 flex-[999_1_560px] flex-col gap-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-3.5 left-3 size-4 text-muted-foreground" />
                            <Input
                                aria-label="Find an item"
                                placeholder="Find an item or SKU"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-9"
                                autoFocus
                            />
                        </div>
                        <ToggleButtons
                            label="Category"
                            size="sm"
                            value={category}
                            onChange={setCategory}
                            options={categories.map((c) => ({
                                value: c,
                                label: c,
                            }))}
                        />
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(170px,1fr))] gap-2.5">
                            {shown.map((p) => {
                                const base =
                                    p.units.find((u) => u.is_base) ??
                                    p.units[0];

                                return (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() => add(p)}
                                        className="grid min-h-24 gap-1 rounded-[10px] border border-border bg-card p-3 text-left hover:border-primary"
                                    >
                                        <span className="text-sm leading-snug font-semibold">
                                            {p.name}
                                        </span>
                                        <span className="figure text-xs text-muted-foreground">
                                            {p.sku}
                                        </span>
                                        <span className="figure mt-auto text-sm">
                                            {formatMoney(base.price_cents)}{' '}
                                            <span className="text-muted-foreground">
                                                / {base.name.toLowerCase()}
                                            </span>
                                        </span>
                                        {p.on_hand !== null &&
                                            Number(p.on_hand) <= 0 && (
                                                <span className="text-xs font-semibold text-danger">
                                                    None on hand
                                                </span>
                                            )}
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <aside className="flex min-w-0 flex-[1_1_360px] flex-col gap-4 rounded-[10px] border border-border bg-card p-4 lg:sticky lg:top-4">
                        <div className="grid gap-2">
                            <Combobox
                                id="counter_org"
                                options={organizations.map((o) => ({
                                    value: o.id,
                                    label: o.name,
                                    detail:
                                        o.account_number +
                                        (o.credit_hold ? ' · on hold' : ''),
                                    keywords: o.account_number,
                                }))}
                                value={form.data.organization_id}
                                onChange={(id) =>
                                    form.setData((d) => ({
                                        ...d,
                                        organization_id: id,
                                        customer_id: null,
                                        settlement: id
                                            ? d.settlement
                                            : 'prepaid',
                                    }))
                                }
                                placeholder="Walk-in (or find an account)"
                            />
                            <Combobox
                                id="counter_customer"
                                options={people.map((c) => ({
                                    value: c.id,
                                    label: `${c.first_name} ${c.last_name ?? ''}`.trim(),
                                    detail: c.phone,
                                    keywords: c.phone ?? '',
                                }))}
                                value={form.data.customer_id}
                                onChange={(id) =>
                                    form.setData('customer_id', id)
                                }
                                placeholder="Contact (optional)"
                            />
                        </div>

                        <ul className="grid gap-2">
                            {form.data.lines.length === 0 && (
                                <li className="py-6 text-center text-sm text-muted-foreground">
                                    Tap items to add them.
                                </li>
                            )}
                            {form.data.lines.map((line, i) => {
                                const product = byId.get(line.product_id);

                                if (!product) {
                                    return null;
                                }

                                return (
                                    <li
                                        key={line.key}
                                        className="grid gap-1.5 border-b border-secondary pb-2"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="text-sm font-medium">
                                                {product.name}
                                            </div>
                                            <button
                                                type="button"
                                                aria-label={`Remove ${product.name}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'lines',
                                                        form.data.lines.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    )
                                                }
                                                className="text-muted-foreground hover:text-foreground"
                                            >
                                                <X className="size-4" />
                                            </button>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <div className="flex items-center rounded-lg border border-input">
                                                <button
                                                    type="button"
                                                    aria-label="Less"
                                                    onClick={() =>
                                                        setQty(
                                                            i,
                                                            String(
                                                                Math.max(
                                                                    0,
                                                                    Number(
                                                                        line.quantity,
                                                                    ) - 1,
                                                                ),
                                                            ),
                                                        )
                                                    }
                                                    className="grid size-9 place-items-center"
                                                >
                                                    <Minus className="size-4" />
                                                </button>
                                                <input
                                                    aria-label={`${product.name} quantity`}
                                                    inputMode="decimal"
                                                    value={line.quantity}
                                                    onChange={(e) =>
                                                        setQty(
                                                            i,
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="figure h-9 w-14 border-x border-input text-center"
                                                />
                                                <button
                                                    type="button"
                                                    aria-label="More"
                                                    onClick={() =>
                                                        setQty(
                                                            i,
                                                            String(
                                                                Number(
                                                                    line.quantity,
                                                                ) + 1,
                                                            ),
                                                        )
                                                    }
                                                    className="grid size-9 place-items-center"
                                                >
                                                    <Plus className="size-4" />
                                                </button>
                                            </div>
                                            {product.units.length > 1 ? (
                                                <NativeSelect
                                                    aria-label="Unit"
                                                    value={String(
                                                        line.product_unit_id,
                                                    )}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'lines',
                                                            form.data.lines.map(
                                                                (l, j) =>
                                                                    j === i
                                                                        ? {
                                                                              ...l,
                                                                              product_unit_id:
                                                                                  Number(
                                                                                      e
                                                                                          .target
                                                                                          .value,
                                                                                  ),
                                                                          }
                                                                        : l,
                                                            ),
                                                        )
                                                    }
                                                    className="h-9 w-auto text-sm"
                                                >
                                                    {product.units.map((u) => (
                                                        <option
                                                            key={u.id}
                                                            value={u.id}
                                                        >
                                                            {u.name}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    {product.units[0]?.name.toLowerCase()}
                                                </span>
                                            )}
                                            <span className="figure ml-auto text-sm">
                                                {formatMoney(
                                                    Math.round(
                                                        Number(
                                                            line.quantity || 0,
                                                        ) * priceOf(line, i),
                                                    ),
                                                )}
                                                {prices[i] &&
                                                    prices[i].source !==
                                                        'list' && (
                                                        <span className="block text-right text-[11px] text-success">
                                                            account price
                                                        </span>
                                                    )}
                                            </span>
                                        </div>
                                        <InputError
                                            message={
                                                errors[`lines.${i}.quantity`]
                                            }
                                        />
                                    </li>
                                );
                            })}
                        </ul>

                        <dl className="grid grid-cols-[1fr_auto] gap-y-1 text-sm">
                            <dt className="text-muted-foreground">Subtotal</dt>
                            <dd className="figure text-right">
                                {formatMoney(subtotal)}
                            </dd>
                            <dt className="text-muted-foreground">Tax</dt>
                            <dd className="figure text-right">
                                {tax !== null
                                    ? formatMoney(tax)
                                    : 'At checkout'}
                            </dd>
                            <dt className="border-t border-border pt-2 text-lg font-bold">
                                Total
                            </dt>
                            <dd className="figure border-t border-border pt-2 text-right text-lg font-bold">
                                {formatMoney(total)}
                            </dd>
                        </dl>
                        <p className="-mt-2 text-xs text-muted-foreground">
                            Container and pallet deposits are added at checkout.
                        </p>

                        {org && (
                            <ToggleButtons
                                label="Payment"
                                value={form.data.settlement}
                                onChange={(v) => form.setData('settlement', v)}
                                options={[
                                    { value: 'prepaid', label: 'Pay now' },
                                    {
                                        value: 'account',
                                        label: `Charge to ${org.name}`,
                                    },
                                ]}
                            />
                        )}
                        {form.data.settlement === 'prepaid' ? (
                            <div className="grid gap-2">
                                <ToggleButtons
                                    label="Tender"
                                    size="sm"
                                    value={form.data.tender}
                                    onChange={(v) => form.setData('tender', v)}
                                    options={tenders}
                                />
                                {form.data.tender === 'cash' ? (
                                    <div className="flex items-center gap-2">
                                        <Input
                                            aria-label="Cash tendered"
                                            inputMode="decimal"
                                            placeholder="Cash tendered"
                                            value={form.data.tendered}
                                            onChange={(e) =>
                                                form.setData(
                                                    'tendered',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {change !== null && change >= 0 && (
                                            <span className="figure text-sm font-semibold whitespace-nowrap text-success">
                                                Change {formatMoney(change)}
                                            </span>
                                        )}
                                    </div>
                                ) : (
                                    <Input
                                        aria-label="Reference"
                                        placeholder={
                                            form.data.tender === 'card_terminal'
                                                ? 'Terminal auth (optional)'
                                                : 'Reference (optional)'
                                        }
                                        value={form.data.reference}
                                        onChange={(e) =>
                                            form.setData(
                                                'reference',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </div>
                        ) : (
                            <Input
                                aria-label="PO number"
                                placeholder="PO number"
                                value={form.data.po_number}
                                onChange={(e) =>
                                    form.setData('po_number', e.target.value)
                                }
                            />
                        )}

                        {Object.entries(errors)
                            .filter(([k]) => !k.startsWith('lines.'))
                            .map(([k, m]) => (
                                <InputError key={k} message={m} />
                            ))}

                        <button
                            type="button"
                            disabled={
                                form.processing || form.data.lines.length === 0
                            }
                            onClick={() =>
                                form.post(CounterController.store.url())
                            }
                            className={cn(
                                'h-14 rounded-xl bg-primary text-lg font-bold text-white hover:bg-[#9a3412] disabled:opacity-50',
                            )}
                        >
                            {form.data.settlement === 'account'
                                ? 'Charge to account'
                                : `Take ${formatMoney(total)}`}
                        </button>
                        {form.data.lines.length > 0 && (
                            <Button
                                variant="ghost"
                                onClick={() => form.setData('lines', [])}
                            >
                                Clear sale
                            </Button>
                        )}
                        <p className="text-center text-xs text-muted-foreground">
                            {formatQuantity(String(form.data.lines.length))}{' '}
                            {form.data.lines.length === 1 ? 'line' : 'lines'} ·{' '}
                            {yard.name}
                        </p>
                    </aside>
                </div>
            </PageBody>
        </>
    );
}

Counter.layout = {
    breadcrumbs: [{ title: 'Counter', href: CounterController.show.url() }],
};
