import { Head, useForm } from '@inertiajs/react';
import QuoteController from '@/actions/App/Http/Controllers/QuoteController';
import InputError from '@/components/input-error';
import {
    formatDate,
    formatMoney,
    formatQuantity,
    unitLabel,
} from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    quote: {
        number: string;
        revision: number;
        status: 'open' | 'accepted' | 'expired' | 'ordered' | 'cancelled';
        quoted_at: string | null;
        expires_on: string | null;
        accepted_at: string | null;
        accepted_by: string | null;
        organization: string | null;
        customer: string | null;
        po_number: string | null;
        fulfillment: 'pickup' | 'delivery';
        delivery_address: {
            line1?: string | null;
            city?: string | null;
            postal_code?: string | null;
        } | null;
        yard: { name: string; address: string };
        lines: {
            id: number;
            sku: string | null;
            description: string;
            quantity: string;
            unit: string;
            unit_price_cents: number;
            line_total_cents: number;
            deposit_cents: number;
        }[];
        subtotal_cents: number;
        delivery_fee_cents: number;
        pallet_fee_cents: number;
        tax_cents: number;
        deposit_cents: number;
        total_cents: number;
    };
    business: { name: string };
    token: string;
};

const banners: Record<
    Props['quote']['status'],
    { text: (q: Props['quote']) => string; tone: string } | null
> = {
    open: null,
    accepted: {
        text: (q) =>
            `Accepted by ${q.accepted_by} on ${formatDate(q.accepted_at)}. The yard will confirm your order.`,
        tone: 'bg-success-soft text-success',
    },
    expired: {
        text: (q) =>
            `This quote expired on ${formatDate(q.expires_on)}. Call the yard for an updated price.`,
        tone: 'bg-warning-soft text-warning',
    },
    ordered: {
        text: () => 'This quote is now an order. Thanks!',
        tone: 'bg-success-soft text-success',
    },
    cancelled: {
        text: () => 'This quote was withdrawn.',
        tone: 'bg-secondary text-subtle',
    },
};

/**
 * What the customer sees from the quote email: lines, totals, and accept.
 */
export default function PublicQuote({ quote, business, token }: Props) {
    const form = useForm({
        name: quote.customer ?? '',
        po_number: quote.po_number ?? '',
    });
    const banner = banners[quote.status];
    const where =
        quote.fulfillment === 'delivery' && quote.delivery_address
            ? `Delivered to ${[quote.delivery_address.line1, quote.delivery_address.city, quote.delivery_address.postal_code].filter(Boolean).join(', ')}`
            : `Pick up at ${quote.yard.name}${quote.yard.address ? `, ${quote.yard.address}` : ''}`;

    return (
        <>
            <Head title={`Quote ${quote.number} · ${business.name}`} />
            <div className="min-h-svh bg-background px-4 py-8 text-[15px] sm:px-6">
                <main className="mx-auto flex max-w-3xl flex-col gap-5">
                    <header className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <div className="text-sm font-semibold">
                                {business.name}
                            </div>
                            <h1 className="text-3xl font-bold">
                                Quote {quote.number}
                            </h1>
                            <p className="mt-1 text-muted-foreground">
                                {quote.organization ?? quote.customer}
                                {quote.revision > 1
                                    ? ` · revision ${quote.revision}`
                                    : ''}
                                {quote.quoted_at
                                    ? ` · sent ${formatDate(quote.quoted_at)}`
                                    : ''}
                            </p>
                        </div>
                        {quote.expires_on && quote.status === 'open' && (
                            <div className="rounded-full bg-accent-soft px-3 py-1.5 text-sm font-semibold text-[#9a3412]">
                                Good until {formatDate(quote.expires_on)}
                            </div>
                        )}
                    </header>

                    {banner && (
                        <div
                            className={cn(
                                'rounded-lg px-4 py-3 text-sm font-medium',
                                banner.tone,
                            )}
                        >
                            {banner.text(quote)}
                        </div>
                    )}

                    <section className="overflow-hidden rounded-[10px] border border-border bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[520px] border-collapse text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium">
                                            Item
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Quantity
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Price
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {quote.lines.map((line) => (
                                        <tr
                                            key={line.id}
                                            className="border-b border-secondary"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {line.description}
                                                </div>
                                                {line.sku && (
                                                    <div className="figure text-xs text-muted-foreground">
                                                        {line.sku}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="figure px-4 py-3 text-right whitespace-nowrap">
                                                {formatQuantity(line.quantity)}{' '}
                                                {unitLabel(
                                                    line.unit,
                                                ).toLowerCase()}
                                            </td>
                                            <td className="figure px-4 py-3 text-right">
                                                {formatMoney(
                                                    line.unit_price_cents,
                                                )}
                                            </td>
                                            <td className="figure px-4 py-3 text-right">
                                                {formatMoney(
                                                    line.line_total_cents,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <dl className="ml-auto grid max-w-xs gap-1.5 p-4 text-sm">
                            {quote.delivery_fee_cents > 0 && (
                                <div className="flex justify-between">
                                    <dt>Delivery (included below)</dt>
                                    <dd className="figure">
                                        {formatMoney(quote.delivery_fee_cents)}
                                    </dd>
                                </div>
                            )}
                            {quote.pallet_fee_cents > 0 && (
                                <div className="flex justify-between">
                                    <dt>
                                        Pallet usage fees (included below, not
                                        refundable)
                                    </dt>
                                    <dd className="figure">
                                        {formatMoney(quote.pallet_fee_cents)}
                                    </dd>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <dt>Subtotal</dt>
                                <dd className="figure">
                                    {formatMoney(quote.subtotal_cents)}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Tax</dt>
                                <dd className="figure">
                                    {formatMoney(quote.tax_cents)}
                                </dd>
                            </div>
                            {quote.deposit_cents > 0 && (
                                <div className="flex justify-between">
                                    <dt>Refundable deposits</dt>
                                    <dd className="figure">
                                        {formatMoney(quote.deposit_cents)}
                                    </dd>
                                </div>
                            )}
                            <div className="flex justify-between border-t border-border pt-2 text-lg font-bold">
                                <dt>Total</dt>
                                <dd className="figure">
                                    {formatMoney(
                                        quote.total_cents + quote.deposit_cents,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <p className="text-sm text-muted-foreground">{where}.</p>

                    {quote.status === 'open' && (
                        <form
                            className="grid gap-3 rounded-[10px] border border-border bg-card p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(QuoteController.accept.url(token), {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <label className="grid gap-1">
                                <span className="text-sm font-medium">
                                    Your name
                                </span>
                                <input
                                    required
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    className="h-11 rounded-lg border border-input bg-card px-3"
                                />
                                <InputError message={form.errors.name} />
                            </label>
                            <label className="grid gap-1">
                                <span className="text-sm font-medium">
                                    PO number (optional)
                                </span>
                                <input
                                    value={form.data.po_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'po_number',
                                            e.target.value,
                                        )
                                    }
                                    className="h-11 rounded-lg border border-input bg-card px-3"
                                />
                            </label>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="h-11 rounded-lg bg-primary px-5 font-semibold text-white hover:bg-[#9a3412] disabled:opacity-50"
                            >
                                Accept quote
                            </button>
                            {Object.entries(form.errors)
                                .filter(([k]) => k !== 'name')
                                .map(([k, m]) => (
                                    <div key={k} className="sm:col-span-3">
                                        <InputError message={m} />
                                    </div>
                                ))}
                        </form>
                    )}
                </main>
            </div>
        </>
    );
}
