import { Head, Link, router, useForm } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { useState } from 'react';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import { OrderLineList } from '@/components/yard/order-line-list';
import { PortalLayout } from '@/components/yard/portal-layout';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type CalcProduct = {
    id: number;
    name: string;
    unit_id: number | null;
    unit: string;
    factor: number;
    per_pallet: number | null;
    price_cents: number;
} | null;

export type PortalOrder = {
    id: number;
    number: string;
    site: string;
    lines: { qty: string; name: string }[];
    more_lines: number;
    status: string;
    total_cents: number;
    created_at: string;
};

type Props = {
    coming: {
        id: number;
        order_id: number;
        number: string;
        summary: string;
        truck: string | null;
        to: string;
        eta: string | null;
        status: string;
        date: string | null;
    }[];
    orders: PortalOrder[];
    calculator: {
        pavers: CalcProduct;
        base: CalcProduct;
        bedding: CalcProduct;
        polymeric: CalcProduct;
    };
    constants: {
        waste: number;
        base_density: number;
        sand_density: number;
        poly_coverage: number;
    };
    account: {
        pallets: number;
        pallet_value_cents: number;
        rentals: {
            id: number;
            number: string;
            equipment: string;
            due: string;
            overdue: boolean;
        }[];
        balance_cents: number | null;
        pickup_requested: boolean;
    };
    bookingUrl: string;
};

export function statusTone(status: string): string {
    if (status.startsWith('Delivered') || status === 'Done') {
        return 'bg-success-soft text-success';
    }

    if (status === 'Out for delivery' || status === 'Ready for pickup') {
        return 'bg-info-soft text-info';
    }

    if (
        status === 'Waiting for the yard' ||
        status === 'Scheduling' ||
        status === 'Being picked'
    ) {
        return 'bg-warning-soft text-warning';
    }

    return 'bg-secondary text-subtle';
}

/**
 * The contractor portal home (yard mockup 6).
 */
export default function PortalHome({
    coming,
    orders,
    calculator,
    constants,
    account,
    bookingUrl,
}: Props) {
    const [area, setArea] = useState('400');
    const [depth, setDepth] = useState('6');
    const pickup = useForm({ count: '' });

    const sqft = Number(area) || 0;
    const inches = Number(depth) || 0;
    const roundHalf = (n: number) => Math.ceil(n * 2) / 2;

    const rows = [
        calculator.pavers && {
            product: calculator.pavers,
            label: `${calculator.pavers.name} (+${Math.round(constants.waste * 100)}% waste)`,
            quantity:
                calculator.pavers.per_pallet && calculator.pavers.factor > 1
                    ? Math.ceil(
                          (sqft * (1 + constants.waste)) /
                              calculator.pavers.factor,
                      )
                    : Math.ceil(sqft * (1 + constants.waste)),
        },
        calculator.base && {
            product: calculator.base,
            label: calculator.base.name,
            quantity: roundHalf(
                ((sqft * inches) / 12 / 27) * constants.base_density,
            ),
        },
        calculator.bedding && {
            product: calculator.bedding,
            label: `${calculator.bedding.name}, bedding`,
            quantity: roundHalf((sqft / 12 / 27) * constants.sand_density),
        },
        calculator.polymeric && {
            product: calculator.polymeric,
            label: calculator.polymeric.name,
            quantity: Math.ceil(sqft / constants.poly_coverage),
        },
    ].filter(
        (
            r,
        ): r is {
            product: NonNullable<CalcProduct>;
            label: string;
            quantity: number;
        } => Boolean(r) && sqft > 0,
    );

    const total = rows.reduce(
        (sum, r) => sum + Math.round(r.quantity * r.product.price_cents),
        0,
    );
    const unitWord = (r: (typeof rows)[number]) =>
        r.product.unit.toLowerCase().includes('pallet')
            ? r.quantity === 1
                ? 'pallet'
                : 'pallets'
            : r.product.unit.toLowerCase() === 'tonne'
              ? 't'
              : r.product.unit.toLowerCase() === 'bag'
                ? r.quantity === 1
                    ? 'bag'
                    : 'bags'
                : r.product.unit.toLowerCase();

    return (
        <PortalLayout active="home" bookingUrl={bookingUrl}>
            <Head title="Contractor account" />
            <h1 className="text-3xl font-bold">
                Good{' '}
                {new Date().getHours() < 12
                    ? 'morning'
                    : new Date().getHours() < 17
                      ? 'afternoon'
                      : 'evening'}
            </h1>

            {coming.map((c) => (
                <section
                    key={c.id}
                    className="flex flex-wrap items-center justify-between gap-4 rounded-[10px] bg-[#1c1917] p-5 text-[#fafaf9]"
                >
                    <div className="flex items-start gap-3">
                        <Truck className="mt-1 size-6 text-[#fb923c]" />
                        <div>
                            <div className="text-xs font-semibold tracking-wide text-[#fb923c] uppercase">
                                {c.status}
                            </div>
                            <div className="text-lg font-semibold">
                                Order {c.number} · {c.summary}
                            </div>
                            <div className="text-sm text-[#d6d3d1]">
                                {[c.truck, c.to ? `to ${c.to}` : null]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-4">
                        {c.eta && (
                            <div className="text-right">
                                <div className="text-xs text-[#d6d3d1]">
                                    ETA
                                </div>
                                <div className="figure text-3xl font-bold">
                                    {c.eta}
                                </div>
                            </div>
                        )}
                        <Link
                            href={PortalController.order.url(c.order_id)}
                            className="rounded-lg bg-[#c2410c] px-4 py-2.5 font-semibold text-white hover:bg-[#9a3412]"
                        >
                            Track
                        </Link>
                    </div>
                </section>
            ))}

            <div className="flex flex-wrap items-start gap-5">
                <div className="flex min-w-0 flex-[999_1_620px] flex-col gap-5">
                    <section className="overflow-hidden rounded-[10px] border border-border bg-card">
                        <div className="flex items-center justify-between px-5 pt-4">
                            <h2 className="text-base font-semibold">
                                Recent orders
                            </h2>
                            <Link
                                href={PortalController.orders.url()}
                                className="text-sm text-muted-foreground hover:underline"
                            >
                                See all
                            </Link>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="mt-2 w-full min-w-[620px] border-collapse text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                        <th className="px-5 py-2 font-medium">
                                            Order
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Job site
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Items
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="px-5 py-2 text-right font-medium">
                                            <span className="sr-only">
                                                Action
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-5 py-6 text-center text-muted-foreground"
                                            >
                                                No orders yet.
                                            </td>
                                        </tr>
                                    )}
                                    {orders.map((o) => (
                                        <tr
                                            key={o.id}
                                            className="border-b border-secondary align-top last:border-0"
                                        >
                                            <td className="px-5 py-3">
                                                <Link
                                                    href={PortalController.order.url(
                                                        o.id,
                                                    )}
                                                    className="figure font-semibold hover:underline"
                                                >
                                                    {o.number}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-3">
                                                {o.site}
                                            </td>
                                            <td className="px-3 py-3">
                                                <OrderLineList
                                                    lines={o.lines}
                                                    more={o.more_lines}
                                                />
                                            </td>
                                            <td className="px-3 py-3">
                                                <span
                                                    className={cn(
                                                        'rounded-full px-2 py-1 text-xs font-semibold',
                                                        statusTone(o.status),
                                                    )}
                                                >
                                                    {o.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(
                                                            PortalController.reorder.url(
                                                                o.id,
                                                            ),
                                                        )
                                                    }
                                                    className="rounded-lg border border-input px-3 py-1.5 text-sm font-medium hover:bg-accent"
                                                >
                                                    Reorder
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section className="grid gap-4 rounded-[10px] border border-border bg-card p-5">
                        <h2 className="text-base font-semibold">
                            Material calculator
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="grid gap-1">
                                <span className="text-sm text-muted-foreground">
                                    Patio area (sq ft)
                                </span>
                                <input
                                    inputMode="decimal"
                                    value={area}
                                    onChange={(e) => setArea(e.target.value)}
                                    className="figure h-11 rounded-lg border border-input px-3 text-lg"
                                />
                            </label>
                            <label className="grid gap-1">
                                <span className="text-sm text-muted-foreground">
                                    Base depth (in)
                                </span>
                                <input
                                    inputMode="decimal"
                                    value={depth}
                                    onChange={(e) => setDepth(e.target.value)}
                                    className="figure h-11 rounded-lg border border-input px-3 text-lg"
                                />
                            </label>
                        </div>
                        <ul className="grid gap-2">
                            {rows.map((r) => (
                                <li
                                    key={r.product.id}
                                    className="flex justify-between gap-3 border-b border-secondary pb-2 text-sm last:border-0"
                                >
                                    <span>{r.label}</span>
                                    <span className="figure font-semibold">
                                        {r.quantity} {unitWord(r)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            disabled={rows.length === 0}
                            onClick={() =>
                                router.post(PortalController.calculate.url(), {
                                    area: sqft,
                                    lines: rows.map((r) => ({
                                        product_id: r.product.id,
                                        product_unit_id: r.product.unit_id,
                                        quantity: r.quantity,
                                    })),
                                })
                            }
                            className="h-12 rounded-xl bg-primary font-bold text-white hover:bg-[#9a3412] disabled:opacity-50"
                        >
                            Add to order at your price · {formatMoney(total)}
                        </button>
                    </section>
                </div>

                <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4 rounded-[10px] border border-border bg-card p-5">
                    <h2 className="text-base font-semibold">Your account</h2>
                    <dl className="grid gap-3 text-sm">
                        <div>
                            <dt className="text-muted-foreground">
                                Pallets on deposit
                            </dt>
                            <dd className="figure text-lg font-semibold">
                                {account.pallets} ·{' '}
                                {formatMoney(account.pallet_value_cents)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Rentals out
                            </dt>
                            <dd>
                                {account.rentals.length === 0
                                    ? 'None'
                                    : account.rentals.map((r) => (
                                          <div
                                              key={r.id}
                                              className={cn(
                                                  r.overdue &&
                                                      'font-semibold text-danger',
                                              )}
                                          >
                                              {r.equipment}, due {r.due}
                                          </div>
                                      ))}
                            </dd>
                        </div>
                        {account.balance_cents !== null && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Statement
                                </dt>
                                <dd>
                                    <Link
                                        href={PortalController.statement.url()}
                                        className="font-semibold underline-offset-4 hover:underline"
                                    >
                                        {formatMoney(account.balance_cents)}{' '}
                                        owing
                                    </Link>
                                </dd>
                            </div>
                        )}
                    </dl>
                    {account.pickup_requested ? (
                        <p className="rounded-lg bg-secondary px-3 py-2 text-sm">
                            A pallet pickup is booked. We'll credit your account
                            when they're back.
                        </p>
                    ) : (
                        account.pallets > 0 && (
                            <form
                                className="grid gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    pickup.post(
                                        PortalController.palletPickup.url(),
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <input
                                    aria-label="How many pallets"
                                    inputMode="numeric"
                                    placeholder={`How many? (${account.pallets} out)`}
                                    required
                                    value={pickup.data.count}
                                    onChange={(e) =>
                                        pickup.setData('count', e.target.value)
                                    }
                                    className="h-11 rounded-lg border border-input px-3"
                                />
                                <button
                                    type="submit"
                                    disabled={pickup.processing}
                                    className="h-11 rounded-lg border border-input font-semibold hover:bg-accent"
                                >
                                    Book a pallet return pickup
                                </button>
                            </form>
                        )
                    )}
                    <a
                        href={bookingUrl}
                        className="text-sm font-medium text-[#9a3412] underline-offset-4 hover:underline"
                    >
                        Rent equipment →
                    </a>
                </aside>
            </div>
        </PortalLayout>
    );
}
