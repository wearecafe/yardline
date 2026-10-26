import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import InputError from '@/components/input-error';
import {
    StopActions,
    StopStatusChip,
} from '@/components/yard/driver-stop-parts';
import { formatQuantity, unitLabel } from '@/lib/format';
import type { DriverStop } from '@/types/driver';

type Props = {
    stop: DriverStop & {
        items: { label: string; quantity: string; unit: string }[];
        po_number: string | null;
        order_number: string | null;
        site_notes: string | null;
        order_notes: string | null;
        truck: string | null;
        date: string | null;
        planned: string | null;
        eta: string | null;
        completed_at: string | null;
    };
    canPickTruck: boolean;
};

function nowPlus(minutes: number): string {
    const d = new Date(Date.now() + minutes * 60_000);

    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/**
 * One stop: where it is, who to call, what's on the truck for it, and
 * the next move (on my way, proof of delivery, done).
 */
export default function DriverStopPage({ stop }: Props) {
    const start = useForm({ eta: stop.eta ?? stop.planned ?? nowPlus(30) });
    const done = useForm({});
    const errors = {
        ...(start.errors as Record<string, string>),
        ...(done.errors as Record<string, string>),
    };
    const isRunStop = stop.kind !== 'delivery';

    return (
        <>
            <Head title={stop.title} />

            <Link
                href={DriverController.today({
                    query: stop.date ? { date: stop.date } : {},
                })}
                className="mb-3 flex items-center gap-1 text-sm text-muted-foreground"
            >
                <ChevronLeft className="size-4" /> My run
            </Link>

            <header className="mb-4">
                <div className="figure text-sm text-muted-foreground">
                    {stop.time_label ?? 'Unscheduled'} · {stop.kind_label}
                    {stop.reference ? ` · ${stop.reference}` : ''}
                </div>
                <h1 className="text-2xl font-bold">{stop.title}</h1>
                <p className="text-muted-foreground">
                    {stop.address_full || 'No address'}
                </p>
                <div className="mt-2">
                    <StopStatusChip stop={stop} />
                </div>
            </header>

            <StopActions stop={stop} className="mb-4" />

            {(stop.needs || stop.site_notes || stop.order_notes) && (
                <section className="mb-4 grid gap-2">
                    {stop.needs && (
                        <p className="rounded-lg bg-warning-soft px-3 py-2 text-sm font-medium text-warning">
                            {stop.needs}
                        </p>
                    )}
                    {stop.site_notes && (
                        <p className="rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning">
                            {stop.site_notes}
                        </p>
                    )}
                    {stop.order_notes && (
                        <p className="rounded-lg bg-secondary px-3 py-2 text-sm">
                            {stop.order_notes}
                        </p>
                    )}
                </section>
            )}

            <section className="mb-4 rounded-[10px] border border-border bg-card p-4">
                {stop.contact && (
                    <p className="mb-3 text-sm">
                        <span className="text-muted-foreground">Contact: </span>
                        {stop.contact.name}
                        {stop.contact.phone && (
                            <>
                                {' · '}
                                <a
                                    href={`tel:${stop.contact.phone}`}
                                    className="font-medium underline underline-offset-4"
                                >
                                    {stop.contact.phone}
                                </a>
                            </>
                        )}
                    </p>
                )}
                {stop.items.length > 0 ? (
                    <ul className="grid gap-2 text-sm">
                        {stop.items.map((item, i) => (
                            <li key={i} className="flex justify-between gap-3">
                                <span>{item.label}</span>
                                {item.unit && (
                                    <span className="figure shrink-0 font-semibold">
                                        {formatQuantity(item.quantity)}{' '}
                                        {unitLabel(item.unit)}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {stop.kind === 'pallet_pickup'
                            ? 'Collect the empty pallets on site and bring them back to the yard.'
                            : 'Nothing listed for this stop.'}
                    </p>
                )}
                {(stop.weight_kg ?? 0) > 0 || stop.po_number ? (
                    <p className="mt-2 border-t border-border pt-2 text-sm text-muted-foreground">
                        {(stop.weight_kg ?? 0) > 0 &&
                            `≈ ${((stop.weight_kg ?? 0) / 1000).toFixed(1)} t`}
                        {(stop.weight_kg ?? 0) > 0 && stop.po_number
                            ? ' · '
                            : ''}
                        {stop.po_number ? `PO ${stop.po_number}` : ''}
                    </p>
                ) : null}
            </section>

            {stop.status === 'scheduled' &&
                (stop.loaded ? (
                    <form
                        className="mb-3 grid gap-2 rounded-[10px] border border-border bg-card p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            start.post(DriverController.start.url(stop.id), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            <span className="text-sm font-medium">
                                Arriving about
                            </span>
                            <input
                                type="time"
                                value={start.data.eta}
                                onChange={(e) =>
                                    start.setData('eta', e.target.value)
                                }
                                className="h-11 rounded-lg border border-input bg-card px-3"
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={start.processing}
                            className="h-14 rounded-xl bg-primary text-lg font-bold text-white hover:bg-[#9a3412] disabled:opacity-50"
                        >
                            On my way
                        </button>
                    </form>
                ) : (
                    <p className="mb-3 rounded-lg bg-secondary px-3 py-2 text-sm">
                        This order isn’t loaded yet. Check with the yard before
                        you leave.
                    </p>
                ))}

            {isRunStop && stop.status !== 'done' && (
                <button
                    type="button"
                    disabled={done.processing}
                    onClick={() =>
                        done.post(DriverController.done.url(stop.id), {
                            preserveScroll: true,
                        })
                    }
                    className="h-14 w-full rounded-xl border-2 border-primary text-lg font-bold text-primary disabled:opacity-50"
                >
                    {stop.kind === 'rental_drop'
                        ? 'Dropped off'
                        : stop.kind === 'rental_pickup'
                          ? 'Picked up'
                          : 'Pallets collected'}
                </button>
            )}

            {Object.values(errors).map((message) => (
                <InputError key={message} message={message} className="mt-2" />
            ))}
        </>
    );
}
