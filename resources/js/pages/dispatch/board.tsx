import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    MapPin,
    MessageSquareText,
} from 'lucide-react';
import { useState } from 'react';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import DispatchController from '@/actions/App/Http/Controllers/DispatchController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Who drives the truck on this day: picked from the drivers ticked for
 * it. That driver sees the truck's run on their phone.
 */
function TruckDriver({
    truckId,
    date,
    driver,
    drivers,
}: {
    truckId: number;
    date: string;
    driver: { id: number; name: string; phone: string | null } | null;
    drivers: { id: number; name: string }[];
}) {
    if (drivers.length === 0 && !driver) {
        return (
            <div className="text-xs text-muted-foreground">
                No drivers ticked for this truck
            </div>
        );
    }

    return (
        <div className="mt-1 grid gap-0.5">
            <select
                aria-label="Driver for the day"
                className="h-8 w-full rounded-md border border-input bg-card px-2 text-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                value={driver ? String(driver.id) : ''}
                onChange={(e) =>
                    router.put(
                        DispatchController.assignDriver.url(truckId),
                        { date, user_id: e.target.value || null },
                        { preserveScroll: true },
                    )
                }
            >
                <option value="">No driver</option>
                {drivers.map((d) => (
                    <option key={d.id} value={String(d.id)}>
                        {d.name}
                    </option>
                ))}
            </select>
            {driver?.phone && (
                <a
                    href={`tel:${driver.phone}`}
                    className="text-xs text-muted-foreground hover:text-foreground"
                >
                    {driver.phone}
                </a>
            )}
        </div>
    );
}

/** Where the driver's phone last put the truck, and how long ago. */
function TruckLocation({
    location,
}: {
    location: NonNullable<Props['trucks'][number]['location']>;
}) {
    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(location.at).getTime()) / 60_000),
    );
    const stale = minutes > 15;
    const ago =
        minutes < 1
            ? 'just now'
            : minutes < 90
              ? `${minutes} min ago`
              : formatDate(location.at);

    return (
        <a
            href={`https://www.google.com/maps/search/?api=1&query=${location.latitude},${location.longitude}`}
            target="_blank"
            rel="noreferrer"
            className={cn(
                'mt-0.5 inline-flex items-center gap-1 text-xs font-medium hover:underline',
                stale ? 'text-muted-foreground' : 'text-success',
            )}
            title="Shared from the driver's phone while their run is open"
        >
            <MapPin className="size-3" /> Seen {ago}
        </a>
    );
}

type Stop = {
    id: number;
    kind: 'delivery' | 'rental_drop' | 'rental_pickup' | 'pallet_pickup';
    rental: boolean;
    title: string;
    where: string;
    address: string;
    detail: string;
    needs: string | null;
    date: string | null;
    starts_at: string | null;
    duration_minutes: number;
    status: string;
    status_label: string;
    eta_sent: boolean;
    truck_id: number | null;
    shipment_id: number | null;
    order_id: number | null;
    rental_id: number | null;
    phone: string | null;
};

type Props = {
    date: string;
    label: string;
    hours: number[];
    trucks: {
        id: number;
        name: string;
        driver: { id: number; name: string; phone: string | null } | null;
        /** Drivers ticked for this truck under Settings → Drivers. */
        drivers: { id: number; name: string }[];
        location: {
            latitude: number;
            longitude: number;
            accuracy_m: number | null;
            at: string;
        } | null;
        capacity: string;
        kind: string;
        stops: Stop[];
    }[];
    unscheduled: Stop[];
    counts: { trucks: number; stops: number };
    etaTexts: boolean;
};

const MATERIAL = 'bg-[#fed7aa] text-[#7c2d12]';
const RENTAL = 'bg-[#bfdbfe] text-[#1e3a8a]';

function shiftDate(date: string, days: number): string {
    const d = new Date(`${date}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

const hourLabel = (h: number) => String(h > 12 ? h - 12 : h);

/**
 * Dispatch board (yard mockup 5): trucks down the side, the day across,
 * material deliveries and rental moves on it. Drag a stop onto a truck.
 */
export default function Board({
    date,
    label,
    hours,
    trucks,
    unscheduled,
    counts,
    etaTexts,
}: Props) {
    const [dragging, setDragging] = useState<Stop | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const all = [...unscheduled, ...trucks.flatMap((t) => t.stops)];
    const selected = all.find((s) => s.id === selectedId) ?? null;
    const cols = `200px repeat(${hours.length}, minmax(0, 1fr))`;

    function drop(truckId: number, hour: number) {
        if (!dragging) {
            return;
        }

        router.patch(
            DispatchController.schedule.url(dragging.id),
            {
                truck_id: truckId,
                date,
                starts_at: `${String(hour).padStart(2, '0')}:00`,
            },
            { preserveScroll: true },
        );
        setDragging(null);
        setOver(null);
    }

    function column(stop: Stop): string {
        const h = Number(stop.starts_at?.slice(0, 2) ?? hours[0]);
        const start = Math.max(0, Math.min(hours.length - 1, h - hours[0]));
        const span = Math.max(
            1,
            Math.min(
                hours.length - start,
                Math.ceil(stop.duration_minutes / 60),
            ),
        );

        return `${start + 2} / span ${span}`;
    }

    return (
        <>
            <Head title="Dispatch" />
            <PageBody>
                <PageHeader
                    title="Dispatch"
                    description={`${label} · ${counts.trucks} ${counts.trucks === 1 ? 'truck' : 'trucks'} · ${counts.stops} ${counts.stops === 1 ? 'stop' : 'stops'}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-3 text-[13px]">
                            <span className="flex items-center gap-1.5">
                                <span
                                    className={cn(
                                        'size-3 rounded-[3px]',
                                        MATERIAL,
                                    )}
                                    aria-hidden="true"
                                />
                                Material delivery
                            </span>
                            <span className="flex items-center gap-1.5">
                                <span
                                    className={cn(
                                        'size-3 rounded-[3px]',
                                        RENTAL,
                                    )}
                                    aria-hidden="true"
                                />
                                Rental drop-off or pickup
                            </span>
                            <span className="flex gap-1">
                                <Button variant="outline" size="icon" asChild>
                                    <Link
                                        href={DispatchController.board.url({
                                            query: {
                                                date: shiftDate(date, -1),
                                            },
                                        })}
                                        aria-label="Day before"
                                    >
                                        <ChevronLeft />
                                    </Link>
                                </Button>
                                <Button variant="outline" size="icon" asChild>
                                    <Link
                                        href={DispatchController.board.url({
                                            query: { date: shiftDate(date, 1) },
                                        })}
                                        aria-label="Next day"
                                    >
                                        <ChevronRight />
                                    </Link>
                                </Button>
                            </span>
                            {etaTexts && (
                                <Button
                                    onClick={() =>
                                        router.post(
                                            DispatchController.etas.url(),
                                            { date },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <MessageSquareText /> Send ETA texts
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        title={`Unscheduled · ${unscheduled.length}`}
                        className="min-w-0 flex-[1_1_260px]"
                    >
                        {unscheduled.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Everything is on a truck.
                            </p>
                        )}
                        <ul className="grid gap-2.5">
                            {unscheduled.map((stop) => (
                                <li key={stop.id}>
                                    <button
                                        type="button"
                                        draggable
                                        onDragStart={(e) => {
                                            e.dataTransfer.effectAllowed =
                                                'move';
                                            setDragging(stop);
                                        }}
                                        onDragEnd={() => setDragging(null)}
                                        onClick={() => setSelectedId(stop.id)}
                                        className={cn(
                                            'grid w-full cursor-grab gap-0.5 rounded-lg border-l-4 bg-secondary p-3 text-left',
                                            stop.rental
                                                ? 'border-[#3b82f6]'
                                                : 'border-[#fb923c]',
                                            selectedId === stop.id &&
                                                'ring-2 ring-primary',
                                        )}
                                    >
                                        <span className="text-sm font-semibold">
                                            {stop.title}
                                        </span>
                                        <span className="text-xs text-subtle">
                                            {stop.detail}
                                        </span>
                                        {(stop.needs ||
                                            (stop.date &&
                                                stop.date !== date)) && (
                                            <span className="text-xs font-semibold text-[#9a3412]">
                                                {[
                                                    stop.date &&
                                                    stop.date !== date
                                                        ? `From ${formatDate(stop.date)}`
                                                        : null,
                                                    stop.needs,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        )}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </Section>

                    <section className="min-w-0 flex-[999_1_720px] overflow-x-auto rounded-[10px] border border-border bg-card">
                        <div className="min-w-[960px] select-none">
                            <div
                                className="grid border-b border-border text-xs text-muted-foreground"
                                style={{ gridTemplateColumns: cols }}
                            >
                                <div className="px-3.5 py-2.5">Truck</div>
                                {hours.map((h) => (
                                    <div
                                        key={h}
                                        className="figure border-l border-secondary px-2 py-2.5"
                                    >
                                        {hourLabel(h)}
                                    </div>
                                ))}
                            </div>
                            {trucks.length === 0 && (
                                <p className="p-8 text-center text-sm text-muted-foreground">
                                    Add your trucks under Settings → Delivery to
                                    schedule runs.
                                </p>
                            )}
                            {trucks.map((truck) => (
                                <div
                                    key={truck.id}
                                    className="grid min-h-[68px] items-center border-b border-secondary"
                                    style={{ gridTemplateColumns: cols }}
                                >
                                    <div className="col-start-1 row-start-1 px-3.5 py-2">
                                        <div className="text-[13px] font-semibold">
                                            {truck.name}
                                        </div>
                                        <TruckDriver
                                            truckId={truck.id}
                                            date={date}
                                            driver={truck.driver}
                                            drivers={truck.drivers}
                                        />
                                        {truck.capacity && (
                                            <div className="text-xs text-muted-foreground">
                                                {truck.capacity}
                                            </div>
                                        )}
                                        {truck.location && (
                                            <TruckLocation
                                                location={truck.location}
                                            />
                                        )}
                                    </div>
                                    {hours.map((h, i) => {
                                        const key = `${truck.id}:${h}`;

                                        return (
                                            <div
                                                key={h}
                                                style={{
                                                    gridColumn: i + 2,
                                                    gridRow: 1,
                                                }}
                                                className={cn(
                                                    'h-full min-h-[68px] border-l border-secondary',
                                                    over === key &&
                                                        'bg-accent-soft',
                                                )}
                                                onDragOver={(e) => {
                                                    if (dragging) {
                                                        e.preventDefault();
                                                        setOver(key);
                                                    }
                                                }}
                                                onDragLeave={() =>
                                                    setOver(null)
                                                }
                                                onDrop={(e) => {
                                                    e.preventDefault();
                                                    drop(truck.id, h);
                                                }}
                                            />
                                        );
                                    })}
                                    {truck.stops.map((stop) => (
                                        <button
                                            key={stop.id}
                                            type="button"
                                            draggable={stop.status !== 'done'}
                                            onDragStart={(e) => {
                                                e.dataTransfer.effectAllowed =
                                                    'move';
                                                setDragging(stop);
                                            }}
                                            onDragEnd={() => setDragging(null)}
                                            onClick={() =>
                                                setSelectedId(stop.id)
                                            }
                                            style={{
                                                gridRow: 1,
                                                gridColumn: column(stop),
                                            }}
                                            className={cn(
                                                'relative z-10 mx-1 grid gap-0.5 overflow-hidden rounded-lg px-2.5 py-1.5 text-left text-xs',
                                                stop.rental ? RENTAL : MATERIAL,
                                                stop.status === 'done' &&
                                                    'opacity-60',
                                                selectedId === stop.id &&
                                                    'ring-2 ring-primary',
                                            )}
                                        >
                                            <span className="truncate font-semibold">
                                                {stop.title}
                                            </span>
                                            <span className="truncate">
                                                {stop.where}
                                            </span>
                                            <span className="truncate font-medium">
                                                {stop.status_label}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </section>
                </div>

                {selected && (
                    <StopPanel
                        stop={selected}
                        date={date}
                        onClose={() => setSelectedId(null)}
                    />
                )}
            </PageBody>
        </>
    );
}

function StopPanel({
    stop,
    date,
    onClose,
}: {
    stop: Stop;
    date: string;
    onClose: () => void;
}) {
    const [eta, setEta] = useState(stop.starts_at ?? '');
    const scheduleUrl = DispatchController.schedule.url(stop.id);

    return (
        <Section
            title={stop.title}
            description={[stop.address, stop.needs].filter(Boolean).join(' · ')}
            actions={
                <Button variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <div className="flex flex-wrap items-end gap-3 text-sm">
                <span className="rounded-full bg-secondary px-3 py-1 font-medium">
                    {stop.status_label}
                </span>
                {stop.eta_sent && (
                    <span className="text-muted-foreground">ETA text sent</span>
                )}
                {stop.phone && (
                    <a
                        href={`tel:${stop.phone}`}
                        className="underline underline-offset-4"
                    >
                        {stop.phone}
                    </a>
                )}
                {stop.order_id && (
                    <Link
                        href={OrderController.show(stop.order_id)}
                        className="underline underline-offset-4"
                    >
                        Order
                    </Link>
                )}
                {stop.rental_id && (
                    <Link
                        href={RentalController.show(stop.rental_id)}
                        className="underline underline-offset-4"
                    >
                        Rental
                    </Link>
                )}
            </div>
            <div className="flex flex-wrap items-end gap-2">
                {stop.status === 'scheduled' && (
                    <>
                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground">ETA</span>
                            <Input
                                type="time"
                                value={eta}
                                onChange={(e) => setEta(e.target.value)}
                                className="w-32"
                            />
                        </label>
                        <Button
                            onClick={() =>
                                router.post(
                                    DispatchController.enRoute.url(stop.id),
                                    { eta: eta || null },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            On its way
                        </Button>
                    </>
                )}
                {stop.shipment_id &&
                    (stop.status === 'scheduled' ||
                        stop.status === 'en_route') && (
                        <Button variant="outline" asChild>
                            <Link
                                href={DeliveryController.show.url(
                                    stop.shipment_id,
                                )}
                            >
                                Proof of delivery
                            </Link>
                        </Button>
                    )}
                {stop.kind !== 'delivery' &&
                    (stop.status === 'scheduled' ||
                        stop.status === 'en_route') && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    DispatchController.done.url(stop.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {stop.kind === 'rental_drop'
                                ? 'Dropped off'
                                : 'Picked up'}
                        </Button>
                    )}
                {stop.truck_id && stop.status !== 'done' && (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            router.patch(
                                scheduleUrl,
                                { truck_id: null, date, starts_at: null },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Unschedule
                    </Button>
                )}
            </div>
        </Section>
    );
}

Board.layout = {
    breadcrumbs: [{ title: 'Dispatch', href: DispatchController.board.url() }],
};
