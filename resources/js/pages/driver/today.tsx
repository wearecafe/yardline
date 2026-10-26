import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Construction } from 'lucide-react';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import {
    StopActions,
    StopStatusChip,
} from '@/components/yard/driver-stop-parts';
import { NativeSelect } from '@/components/yard/field';
import { cn } from '@/lib/utils';
import type { DriverStop } from '@/types/driver';

type Props = {
    date: string;
    label: string;
    longLabel: string;
    isToday: boolean;
    prev: string;
    next: string;
    trucks: { id: number; name: string; driver: string | null }[];
    truckIds: number[];
    canPickTruck: boolean;
    stops: DriverStop[];
    summary: { total: number; done: number; weight_kg: number };
    driver: string;
};

/**
 * The driver's run for the day: stops in order, the next one up top with
 * directions, a call button and proof of delivery.
 */
export default function DriverToday({
    date,
    label,
    longLabel,
    isToday,
    prev,
    next,
    trucks,
    truckIds,
    canPickTruck,
    stops,
    summary,
}: Props) {
    const nextStop = stops.find((s) => s.status !== 'done') ?? null;
    const truckQuery =
        canPickTruck && truckIds[0] ? { truck: truckIds[0] } : {};
    const myTrucks = trucks.filter((t) => truckIds.includes(t.id));

    return (
        <>
            <Head title={`${label}'s run`} />

            <div className="mb-4 flex items-center justify-between gap-2">
                <Link
                    href={DriverController.today({
                        query: { date: prev, ...truckQuery },
                    })}
                    className="rounded-lg p-2 text-muted-foreground hover:bg-secondary"
                    aria-label="Previous day"
                >
                    <ChevronLeft className="size-5" />
                </Link>
                <div className="text-center">
                    <h1 className="text-xl font-bold">{label}</h1>
                    <div className="text-sm text-muted-foreground">
                        {longLabel}
                    </div>
                </div>
                <Link
                    href={DriverController.today({
                        query: { date: next, ...truckQuery },
                    })}
                    className="rounded-lg p-2 text-muted-foreground hover:bg-secondary"
                    aria-label="Next day"
                >
                    <ChevronRight className="size-5" />
                </Link>
            </div>

            {canPickTruck ? (
                <NativeSelect
                    aria-label="Truck"
                    className="mb-4 h-11"
                    value={String(truckIds[0] ?? '')}
                    onChange={(e) =>
                        router.get(
                            DriverController.today.url({
                                query: { date, truck: e.target.value },
                            }),
                        )
                    }
                >
                    {trucks.map((t) => (
                        <option key={t.id} value={String(t.id)}>
                            {t.name}
                            {t.driver ? ` · ${t.driver}` : ''}
                        </option>
                    ))}
                </NativeSelect>
            ) : (
                myTrucks.length > 0 && (
                    <p className="mb-4 text-center text-sm font-medium">
                        {myTrucks.map((t) => t.name).join(' · ')}
                    </p>
                )
            )}

            {trucks.length === 0 ? (
                <div className="rounded-[10px] border border-border bg-card p-6 text-center">
                    <Construction className="mx-auto mb-2 size-8 text-muted-foreground" />
                    <p className="font-semibold">No truck yet</p>
                    <p className="text-sm text-muted-foreground">
                        Ask the office to put you on a truck under Settings →
                        Delivery. Your run shows up here once they do.
                    </p>
                </div>
            ) : stops.length === 0 ? (
                <div className="rounded-[10px] border border-border bg-card p-6 text-center">
                    <p className="font-semibold">
                        Nothing scheduled {isToday ? 'today' : 'this day'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Stops appear here as dispatch puts them on your truck.
                    </p>
                </div>
            ) : (
                <>
                    <p className="mb-3 text-sm text-muted-foreground">
                        {summary.total} {summary.total === 1 ? 'stop' : 'stops'}
                        {' · '}
                        {summary.done} done
                        {summary.weight_kg > 0
                            ? ` · ≈ ${(summary.weight_kg / 1000).toFixed(1)} t on the truck`
                            : ''}
                    </p>

                    <ol className="grid gap-3">
                        {stops.map((stop, index) => {
                            const isNext = isToday && stop.id === nextStop?.id;

                            return (
                                <li
                                    key={stop.id}
                                    className={cn(
                                        'rounded-[10px] border bg-card',
                                        isNext
                                            ? 'border-primary shadow-sm'
                                            : 'border-border',
                                        stop.status === 'done' && 'opacity-70',
                                    )}
                                >
                                    <Link
                                        href={DriverController.show(stop.id)}
                                        className="block p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="figure text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                    {index + 1}.{' '}
                                                    {stop.time_label ?? '—'}
                                                    {' · '}
                                                    {stop.kind_label}
                                                    {isNext && (
                                                        <span className="ml-1 text-primary">
                                                            · Next
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="truncate text-lg font-bold">
                                                    {stop.title}
                                                </div>
                                                <div className="text-sm text-muted-foreground">
                                                    {stop.address ||
                                                        'No address'}
                                                </div>
                                            </div>
                                            <StopStatusChip stop={stop} />
                                        </div>
                                        {stop.needs && (
                                            <div className="mt-2 text-sm font-medium text-warning">
                                                {stop.needs}
                                            </div>
                                        )}
                                    </Link>
                                    {isNext && (
                                        <StopActions
                                            stop={stop}
                                            className="px-4 pb-4"
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                </>
            )}
        </>
    );
}
