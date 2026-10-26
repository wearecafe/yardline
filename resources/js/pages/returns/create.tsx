import { Head, Link, useForm } from '@inertiajs/react';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import InputError from '@/components/input-error';
import { Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney, formatQuantity, unitLabel } from '@/lib/format';

type Line = {
    id: number;
    description: string;
    sku: string | null;
    unit: string;
    unit_price_cents: number;
    shipped: string;
    returned: string;
    returnable: string;
    tax_rate: number;
};

type Props = {
    order: { id: number; number: string; who: string; lines: Line[] };
    restockingPercent: number;
    locations: { id: number; name: string }[];
};

/**
 * Take a return against an order: how much of each line, whether it goes
 * back on the shelf, and the restocking fee.
 */
export default function CreateReturn({
    order,
    restockingPercent,
    locations,
}: Props) {
    const form = useForm({
        lines: order.lines.map((l) => ({
            order_line_id: l.id,
            quantity: '',
            restock: true,
        })),
        reason: '',
        restocking_percent: String(restockingPercent),
        location_id: String(locations[0]?.id ?? ''),
    });

    const totals = form.data.lines.reduce(
        (acc, row, i) => {
            const line = order.lines[i];
            const amount = Math.round(
                Number(row.quantity || 0) * line.unit_price_cents,
            );
            const tax = Math.round(amount * line.tax_rate);
            acc.subtotal += amount;
            acc.tax += tax;
            acc.restockable += row.restock ? amount : 0;

            return acc;
        },
        { subtotal: 0, tax: 0, restockable: 0 },
    );
    const fee = Math.round(
        (totals.restockable * Number(form.data.restocking_percent || 0)) / 100,
    );
    const feeTax =
        totals.subtotal > 0
            ? Math.round((totals.tax * fee) / totals.subtotal)
            : 0;
    const credit = totals.subtotal - fee + totals.tax - feeTax;
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head title={`Return on ${order.number}`} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <Link
                            href={OrderController.show(order.id)}
                            className="figure hover:underline"
                        >
                            {order.number}
                        </Link>
                    }
                    title="Take a return"
                    description={order.who}
                />
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(ReturnController.store.url(order.id));
                    }}
                >
                    <Section flush>
                        <Table minWidth={700}>
                            <thead>
                                <tr>
                                    <Th>Item</Th>
                                    <Th numeric>Shipped</Th>
                                    <Th numeric>Coming back</Th>
                                    <Th>Condition</Th>
                                    <Th numeric>Credit</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.lines.map((line, i) => {
                                    const row = form.data.lines[i];
                                    const set = (patch: Partial<typeof row>) =>
                                        form.setData(
                                            'lines',
                                            form.data.lines.map((r, j) =>
                                                j === i
                                                    ? { ...r, ...patch }
                                                    : r,
                                            ),
                                        );

                                    return (
                                        <tr key={line.id}>
                                            <Td>
                                                <div className="font-medium">
                                                    {line.description}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {formatMoney(
                                                        line.unit_price_cents,
                                                    )}{' '}
                                                    / {unitLabel(line.unit)}
                                                </div>
                                            </Td>
                                            <Td numeric>
                                                {formatQuantity(line.shipped)}{' '}
                                                {unitLabel(line.unit)}
                                                {Number(line.returned) > 0 && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {formatQuantity(
                                                            line.returned,
                                                        )}{' '}
                                                        back already
                                                    </div>
                                                )}
                                            </Td>
                                            <Td numeric>
                                                <Input
                                                    aria-label={`${line.description} coming back`}
                                                    inputMode="decimal"
                                                    placeholder="0"
                                                    disabled={
                                                        Number(
                                                            line.returnable,
                                                        ) <= 0
                                                    }
                                                    value={row.quantity}
                                                    onChange={(e) =>
                                                        set({
                                                            quantity:
                                                                e.target.value,
                                                        })
                                                    }
                                                    className="figure ml-auto w-24 text-right"
                                                />
                                            </Td>
                                            <Td>
                                                <NativeSelect
                                                    aria-label="Condition"
                                                    value={
                                                        row.restock
                                                            ? 'good'
                                                            : 'damaged'
                                                    }
                                                    onChange={(e) =>
                                                        set({
                                                            restock:
                                                                e.target
                                                                    .value ===
                                                                'good',
                                                        })
                                                    }
                                                    className="w-auto"
                                                >
                                                    <option value="good">
                                                        Back on the shelf
                                                    </option>
                                                    <option value="damaged">
                                                        Damaged, write off
                                                    </option>
                                                </NativeSelect>
                                            </Td>
                                            <Td numeric>
                                                {row.quantity
                                                    ? formatMoney(
                                                          Math.round(
                                                              Number(
                                                                  row.quantity,
                                                              ) *
                                                                  line.unit_price_cents,
                                                          ),
                                                      )
                                                    : '—'}
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </Section>

                    <div className="flex flex-wrap items-start gap-5">
                        <Section className="min-w-0 flex-[999_1_420px]">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field
                                    label="Reason"
                                    htmlFor="reason"
                                    className="sm:col-span-3"
                                >
                                    <Input
                                        id="reason"
                                        value={form.data.reason}
                                        onChange={(e) =>
                                            form.setData(
                                                'reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Over-ordered, 3 pallets unopened"
                                    />
                                </Field>
                                <Field
                                    label="Restocking fee (%)"
                                    htmlFor="restocking_percent"
                                    error={errors.restocking_percent}
                                    hint="On goods going back on the shelf."
                                >
                                    <Input
                                        id="restocking_percent"
                                        inputMode="decimal"
                                        value={form.data.restocking_percent}
                                        onChange={(e) =>
                                            form.setData(
                                                'restocking_percent',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Back into" htmlFor="location_id">
                                    <NativeSelect
                                        id="location_id"
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
                            </div>
                            {(errors.lines || errors.order) && (
                                <InputError
                                    message={errors.lines ?? errors.order}
                                />
                            )}
                        </Section>
                        <Section
                            title="Credit"
                            className="min-w-0 flex-[1_1_280px]"
                        >
                            <dl className="grid grid-cols-[1fr_auto] gap-y-1.5 text-sm">
                                <dt className="text-muted-foreground">Goods</dt>
                                <dd className="figure text-right">
                                    {formatMoney(totals.subtotal)}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Restocking fee
                                </dt>
                                <dd className="figure text-right">
                                    −{formatMoney(fee)}
                                </dd>
                                <dt className="text-muted-foreground">Tax</dt>
                                <dd className="figure text-right">
                                    {formatMoney(totals.tax - feeTax)}
                                </dd>
                                <dt className="border-t border-border pt-2 font-semibold">
                                    Credit
                                </dt>
                                <dd className="figure border-t border-border pt-2 text-right font-semibold">
                                    {formatMoney(credit)}
                                </dd>
                            </dl>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing || totals.subtotal <= 0
                                }
                            >
                                Issue credit note
                            </Button>
                        </Section>
                    </div>
                </form>
            </PageBody>
        </>
    );
}
