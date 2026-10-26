import { Head, useForm } from '@inertiajs/react';
import { Check, Wrench } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import BookingController from '@/actions/App/Http/Controllers/Rentals/BookingController';
import InputError from '@/components/input-error';
import {
    addDays,
    BookingDates,
    monthOf,
} from '@/components/yard/booking-dates';
import { PortalLayout } from '@/components/yard/portal-layout';
import { formatMoney } from '@/lib/format';
import { getJson } from '@/lib/json';
import { cn } from '@/lib/utils';
import type { RentalEstimate } from '@/types';

type Product = {
    id: number;
    name: string;
    category: string;
    use_case: string | null;
    day_cents: number;
    week_cents: number | null;
    deposit_cents: number;
    available_now: boolean;
    available_from: string | null;
};

type Props = {
    yard: { name: string; slug: string };
    settings: {
        damage_waiver_percent: number;
        free_sunday: boolean;
        opening_time: string;
        closing_time: string;
    };
    today: string;
    products: Product[];
    /** Set when a contractor is signed in to this yard's portal. */
    portal: {
        customer: { id: number; name: string; first_name: string };
        organization: {
            id: number;
            name: string;
            account_number: string;
        } | null;
        yard: { name: string; slug: string };
    } | null;
    account: {
        first_name: string;
        last_name: string;
        email: string;
        phone: string;
        on_file: { kind: string; label: string; detail: string }[];
    } | null;
};

const inputClass =
    'h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 read-only:bg-muted read-only:text-muted-foreground';

function shortDate(day: string): string {
    return new Date(`${day}T12:00:00Z`).toLocaleDateString('en-CA', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    });
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="grid min-w-0 gap-1">
            <span className="text-xs text-muted-foreground">{label}</span>
            {children}
        </label>
    );
}

/**
 * The yard's equipment booking page (mockups 6 and 6b). Public, or inside
 * the contractor portal's frame with the renter's details filled in when a
 * contractor is signed in.
 */
export default function Book({
    yard,
    settings,
    today,
    products,
    portal,
    account,
}: Props) {
    const categories = useMemo(
        () => ['All', ...new Set(products.map((p) => p.category))],
        [products],
    );
    const [category, setCategory] = useState('All');
    const shown =
        category === 'All'
            ? products
            : products.filter((p) => p.category === category);
    const [estimate, setEstimate] = useState<RentalEstimate | null>(null);

    const form = useForm({
        product_id:
            products.find((p) => p.available_now)?.id ?? products[0]?.id ?? 0,
        pickup_on: addDays(today, 1),
        return_on: addDays(today, 1),
        damage_waiver: true,
        first_name: account?.first_name ?? '',
        last_name: account?.last_name ?? '',
        email: account?.email ?? '',
        phone: account?.phone ?? '',
    });
    const product = products.find((p) => p.id === form.data.product_id);
    const [month, setMonth] = useState(monthOf(addDays(today, 1)));
    const [booked, setBooked] = useState<Set<string>>(new Set());

    useEffect(() => {
        if (!form.data.product_id) {
            return;
        }

        const controller = new AbortController();
        getJson<{ booked: string[] }>(
            BookingController.availability.url(yard.slug),
            { product_id: form.data.product_id, from: month, days: 42 },
            controller.signal,
        )
            .then((r) => setBooked(new Set(r.booked)))
            .catch(() => {});

        return () => controller.abort();
    }, [form.data.product_id, month, yard.slug]);

    useEffect(() => {
        if (!form.data.product_id) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            getJson<RentalEstimate>(
                BookingController.quote.url(yard.slug),
                {
                    product_id: form.data.product_id,
                    pickup_on: form.data.pickup_on,
                    return_on: form.data.return_on,
                    damage_waiver: form.data.damage_waiver,
                },
                controller.signal,
            )
                .then(setEstimate)
                .catch(() => {});
        }, 200);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [
        form.data.product_id,
        form.data.pickup_on,
        form.data.return_on,
        form.data.damage_waiver,
        yard.slug,
    ]);

    const line = estimate?.lines?.[0];
    const available = estimate?.valid && estimate.problems.length === 0;

    const licenceOnFile =
        account?.on_file.some((d) => d.kind === 'drivers_licence') ?? false;

    const content = (
        <>
            <div>
                <h1 className="text-3xl font-bold">
                    Rent the tools for the job
                </h1>
                <p className="mt-1.5 text-muted-foreground">
                    Live availability. Pick up at the yard,{' '}
                    {settings.opening_time} to {settings.closing_time}.
                    {portal?.organization &&
                        ` Booked to ${portal.organization.name}’s account.`}
                </p>
            </div>

            <div
                className="flex flex-wrap gap-2"
                role="group"
                aria-label="Categories"
            >
                {categories.map((name) => (
                    <button
                        key={name}
                        type="button"
                        aria-pressed={name === category}
                        onClick={() => setCategory(name)}
                        className={cn(
                            'h-11 rounded-full px-4',
                            name === category
                                ? 'bg-[#1c1917] font-semibold text-[#fafaf9]'
                                : 'border border-input bg-card hover:bg-accent',
                        )}
                    >
                        {name}
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap items-start gap-5">
                <div className="grid min-w-0 flex-[999_1_560px] grid-cols-[repeat(auto-fill,minmax(230px,1fr))] gap-4">
                    {shown.length === 0 && (
                        <p className="text-muted-foreground">
                            No equipment is listed for online booking yet. Call
                            the yard to book.
                        </p>
                    )}
                    {shown.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            aria-pressed={p.id === form.data.product_id}
                            onClick={() => form.setData('product_id', p.id)}
                            className={cn(
                                'flex flex-col overflow-hidden rounded-xl border bg-card text-left transition-colors',
                                p.id === form.data.product_id
                                    ? 'border-2 border-primary'
                                    : 'border-border hover:border-[#a8a29e]',
                            )}
                        >
                            <div
                                className="flex aspect-[4/3] items-center justify-center bg-border text-muted-foreground"
                                aria-hidden="true"
                            >
                                <Wrench className="size-8" />
                            </div>
                            <div className="flex flex-1 flex-col gap-1.5 p-3.5">
                                <h2 className="text-base font-semibold">
                                    {p.name}
                                </h2>
                                {p.use_case && (
                                    <div className="text-[13px] text-muted-foreground">
                                        {p.use_case}
                                    </div>
                                )}
                                <div className="figure flex gap-3 text-sm">
                                    <span>
                                        {formatMoney(p.day_cents)} / day
                                    </span>
                                    {p.week_cents && (
                                        <span className="text-muted-foreground">
                                            {formatMoney(p.week_cents)} / wk
                                        </span>
                                    )}
                                </div>
                                <div
                                    className={cn(
                                        'text-[13px] font-semibold',
                                        p.available_now
                                            ? 'text-success'
                                            : 'text-warning',
                                    )}
                                >
                                    {p.available_now
                                        ? 'Available now'
                                        : p.available_from
                                          ? `Available ${shortDate(p.available_from)}`
                                          : 'Call to check availability'}
                                </div>
                            </div>
                        </button>
                    ))}
                </div>

                <aside className="flex min-w-0 flex-[1_1_340px] flex-col gap-4 rounded-xl border border-border bg-card p-5 lg:sticky lg:top-4">
                    <div className="text-xs font-semibold tracking-[0.06em] text-[#9a3412] uppercase">
                        Your booking
                    </div>
                    <h2 className="text-xl font-semibold">
                        {product?.name ?? 'Pick equipment'}
                    </h2>

                    <BookingDates
                        today={today}
                        pickup={form.data.pickup_on}
                        returnOn={form.data.return_on}
                        booked={booked}
                        month={month}
                        onMonthChange={setMonth}
                        onChange={(pickup, returnOn) =>
                            form.setData((d) => ({
                                ...d,
                                pickup_on: pickup,
                                return_on: returnOn,
                            }))
                        }
                    />

                    {estimate && (
                        <div
                            className={cn(
                                'rounded-lg px-3 py-2.5 text-sm',
                                available
                                    ? 'bg-success-soft text-success'
                                    : 'bg-danger-soft text-danger',
                            )}
                        >
                            {available
                                ? [
                                      'Available.',
                                      ...(estimate.notes ?? []),
                                  ].join(' ')
                                : estimate.problems.join(' ') ||
                                  'Not available for these dates.'}
                        </div>
                    )}

                    {line && (
                        <div className="grid gap-2">
                            <div className="flex justify-between gap-3">
                                <span>
                                    {line.rate_summary.endsWith('days') ||
                                    line.rate_summary.endsWith('day')
                                        ? `${line.billable_days} billable ${line.billable_days === 1 ? 'day' : 'days'} × ${formatMoney(line.day_rate_cents)}`
                                        : line.rate_summary}
                                </span>
                                <span className="figure">
                                    {formatMoney(line.charge_cents)}
                                </span>
                            </div>
                            <label className="flex items-center justify-between gap-3">
                                <span className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="size-5 accent-primary"
                                        checked={form.data.damage_waiver}
                                        onChange={(e) =>
                                            form.setData(
                                                'damage_waiver',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    Damage waiver{' '}
                                    {settings.damage_waiver_percent}%
                                </span>
                                <span className="figure">
                                    {formatMoney(estimate?.waiver_cents ?? 0)}
                                </span>
                            </label>
                            <div className="flex justify-between gap-3">
                                <span>Tax</span>
                                <span className="figure">
                                    {formatMoney(estimate?.tax_cents ?? 0)}
                                </span>
                            </div>
                            <div className="flex justify-between gap-3 border-t border-border pt-2 text-lg font-bold">
                                <span>Total</span>
                                <span className="figure">
                                    {formatMoney(estimate?.total_cents ?? 0)}
                                </span>
                            </div>
                        </div>
                    )}

                    {product && product.deposit_cents > 0 && (
                        <p className="text-[13px] text-muted-foreground">
                            A {formatMoney(product.deposit_cents)} refundable
                            hold goes on your card at pickup. You’ll sign the
                            agreement online
                            {licenceOnFile
                                ? '.'
                                : ' and show your licence at the counter.'}
                        </p>
                    )}

                    <form
                        className="grid gap-2.5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(BookingController.store.url(yard.slug));
                        }}
                    >
                        {account && (
                            <div className="border-t border-border pt-4 text-xs font-semibold tracking-[0.06em] text-muted-foreground uppercase">
                                Renter · from your account
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-2.5">
                            <Field label="First name">
                                <input
                                    autoComplete="given-name"
                                    required
                                    readOnly={account !== null}
                                    value={form.data.first_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'first_name',
                                            e.target.value,
                                        )
                                    }
                                    className={inputClass}
                                />
                            </Field>
                            <Field label="Last name">
                                <input
                                    autoComplete="family-name"
                                    required={account === null}
                                    readOnly={account !== null}
                                    value={form.data.last_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'last_name',
                                            e.target.value,
                                        )
                                    }
                                    className={inputClass}
                                />
                            </Field>
                        </div>
                        <Field label="Email">
                            <input
                                type="email"
                                autoComplete="email"
                                required={account === null}
                                readOnly={account !== null}
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                className={inputClass}
                            />
                        </Field>
                        <Field label="Mobile phone, for pickup texts">
                            <input
                                type="tel"
                                autoComplete="tel"
                                required
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData('phone', e.target.value)
                                }
                                className={inputClass}
                            />
                        </Field>
                        {account && account.on_file.length > 0 && (
                            <ul className="grid gap-2 rounded-lg bg-muted p-3 text-sm">
                                {account.on_file.map((doc) => (
                                    <li
                                        key={doc.kind}
                                        className="flex items-start gap-2.5"
                                    >
                                        <Check
                                            className="mt-0.5 size-[18px] shrink-0 text-success"
                                            aria-hidden
                                        />
                                        <span className="grid">
                                            <span className="font-semibold">
                                                {doc.label}
                                            </span>
                                            <span className="text-[13px] text-muted-foreground">
                                                {doc.detail}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {Object.values(form.errors).map((message) => (
                            <InputError key={message} message={message} />
                        ))}
                        <button
                            type="submit"
                            disabled={form.processing || !available}
                            className="h-[52px] rounded-[10px] bg-primary text-base font-bold text-white hover:bg-[#9a3412] disabled:opacity-50"
                        >
                            Book and pay
                        </button>
                    </form>
                </aside>
            </div>
        </>
    );

    if (portal) {
        return (
            <PortalLayout active="rent">
                <Head title={`Rent equipment · ${yard.name}`} />
                {content}
            </PortalLayout>
        );
    }

    return (
        <>
            <Head title={`Equipment rental · ${yard.name}`} />
            <div className="min-h-svh bg-background text-[15px]">
                <header className="border-b border-border bg-card">
                    <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-4 px-6 py-3.5">
                        <div className="text-lg font-bold">
                            {yard.name}{' '}
                            <span className="font-normal text-muted-foreground">
                                · Equipment rental
                            </span>
                        </div>
                        <a
                            href={`/portal/${yard.slug}`}
                            className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Contractor sign-in
                        </a>
                    </div>
                </header>
                <main className="mx-auto flex max-w-[1200px] flex-col gap-5 px-6 py-7">
                    {content}
                </main>
            </div>
        </>
    );
}
