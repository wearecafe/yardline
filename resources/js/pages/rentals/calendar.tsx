import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import CalendarController from '@/actions/App/Http/Controllers/Rentals/CalendarController';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import { Notice, PageBody, PageHeader } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Bar = {
    kind: 'out' | 'reserved' | 'overdue' | 'service';
    label: string;
    starts_at: string;
    ends_at: string;
    rental_id: number | null;
    rental_item_id: number | null;
    number: string | null;
    movable: boolean;
};

type Unit = {
    id: number;
    product_id: number;
    name: string;
    serial: string;
    service_due: boolean;
    bars: Bar[];
};

type Props = {
    from: string;
    days: number;
    timezone: string;
    units: Unit[];
    conflicts: { unit_id: number; message: string; rental_id: number | null }[];
};

const barStyles: Record<Bar['kind'], string> = {
    out: 'bg-[#1d4ed8] text-white',
    reserved: 'bg-[#93c5fd] text-[#1e3a8a]',
    overdue: 'bg-[#b91c1c] text-white',
    service: 'bg-[#d97706] text-[#1c1917]',
};

const legend: [Bar['kind'], string][] = [
    ['out', 'On rent'],
    ['reserved', 'Reserved'],
    ['overdue', 'Overdue'],
    ['service', 'Service'],
];

const DAY_MS = 24 * 60 * 60 * 1000;

/** yyyy-mm-dd of a date in the yard's time zone. */
function dayKey(date: Date, timeZone: string): string {
    return date.toLocaleDateString('en-CA', { timeZone });
}

function addDays(isoDay: string, n: number): string {
    const d = new Date(`${isoDay}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);

    return d.toISOString().slice(0, 10);
}

/** Time of day "HH:MM" of an instant, in the yard's time zone. */
function timeOfDay(iso: string, timeZone: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone,
    });
}

export default function Calendar({
    from,
    days,
    timezone,
    units,
    conflicts,
}: Props) {
    const dayList = useMemo(
        () => Array.from({ length: days }, (_, i) => addDays(from, i)),
        [from, days],
    );
    const today = dayKey(new Date(), timezone);
    const [dragging, setDragging] = useState<Bar | null>(null);
    const [dropTarget, setDropTarget] = useState<string | null>(null);
    const [selecting, setSelecting] = useState<{
        unit: Unit;
        start: number;
        end: number;
    } | null>(null);

    /** Grid columns for a bar, clipped to the visible range. */
    function columns(bar: Bar): { start: number; span: number } | null {
        const startDay = dayKey(new Date(bar.starts_at), timezone);
        // A bar ending at midnight belongs to the day before.
        const endDay = dayKey(
            new Date(new Date(bar.ends_at).getTime() - 1),
            timezone,
        );
        const first = dayList[0];
        const last = dayList[dayList.length - 1];

        if (endDay < first || startDay > last) {
            return null;
        }

        const s = Math.max(
            0,
            dayList.indexOf(startDay < first ? first : startDay),
        );
        const e = dayList.indexOf(endDay > last ? last : endDay);

        return { start: s + 2, span: Math.max(1, e - s + 1) };
    }

    function moveTo(unit: Unit, dayIndex: number) {
        if (
            !dragging ||
            dragging.rental_id === null ||
            dragging.rental_item_id === null
        ) {
            return;
        }

        const start = new Date(dragging.starts_at).getTime();
        const length = new Date(dragging.ends_at).getTime() - start;
        const oldDay = dayKey(new Date(dragging.starts_at), timezone);
        const offsetDays =
            (new Date(`${dayList[dayIndex]}T12:00:00Z`).getTime() -
                new Date(`${oldDay}T12:00:00Z`).getTime()) /
            DAY_MS;
        const newStart = new Date(start + offsetDays * DAY_MS);
        const newEnd = new Date(newStart.getTime() + length);

        router.patch(
            RentalController.move.url(dragging.rental_id),
            {
                rental_item_id: dragging.rental_item_id,
                rental_unit_id: unit.id,
                starts_at: `${dayList[dayIndex]}T${timeOfDay(dragging.starts_at, timezone)}`,
                ends_at: `${dayKey(newEnd, timezone)}T${timeOfDay(newEnd.toISOString(), timezone)}`,
            },
            { preserveScroll: true },
        );
    }

    function finishSelection() {
        if (!selecting) {
            return;
        }

        const a = Math.min(selecting.start, selecting.end);
        const b = Math.max(selecting.start, selecting.end);
        setSelecting(null);

        router.get(
            RentalController.create.url({
                query: {
                    rental_unit_id: selecting.unit.id,
                    product_id: selecting.unit.product_id,
                    starts_at: `${dayList[a]}T07:00`,
                    ends_at: `${dayList[b]}T17:00`,
                },
            }),
        );
    }

    const rangeLabel = `${new Date(`${dayList[0]}T12:00:00Z`).toLocaleDateString('en-CA', { month: 'short', day: 'numeric' })} – ${new Date(`${dayList[dayList.length - 1]}T12:00:00Z`).toLocaleDateString('en-CA', { month: 'short', day: 'numeric' })}`;

    return (
        <>
            <Head title="Availability" />
            <PageBody>
                <PageHeader
                    title="Availability"
                    description={`${rangeLabel} · drag a booking to move it, drag across an empty row to book`}
                    actions={
                        <div className="flex flex-wrap items-center gap-3.5 text-[13px]">
                            {legend.map(([kind, label]) => (
                                <span
                                    key={kind}
                                    className="flex items-center gap-1.5"
                                >
                                    <span
                                        className={cn(
                                            'size-3 rounded-[3px]',
                                            barStyles[kind],
                                        )}
                                        aria-hidden="true"
                                    />
                                    {label}
                                </span>
                            ))}
                            <span className="flex gap-1">
                                <Button variant="outline" size="icon" asChild>
                                    <Link
                                        href={CalendarController.url({
                                            query: { from: addDays(from, -7) },
                                        })}
                                        aria-label="Earlier week"
                                    >
                                        <ChevronLeft />
                                    </Link>
                                </Button>
                                <Button variant="outline" size="icon" asChild>
                                    <Link
                                        href={CalendarController.url({
                                            query: { from: addDays(from, 7) },
                                        })}
                                        aria-label="Later week"
                                    >
                                        <ChevronRight />
                                    </Link>
                                </Button>
                            </span>
                        </div>
                    }
                />

                {conflicts.map((conflict) => (
                    <Notice
                        key={`${conflict.unit_id}-${conflict.message}`}
                        tone="danger"
                    >
                        Conflict: {conflict.message}{' '}
                        {conflict.rental_id && (
                            <Link
                                href={RentalController.show(conflict.rental_id)}
                                className="font-semibold text-danger underline underline-offset-4"
                            >
                                Swap to another unit
                            </Link>
                        )}
                    </Notice>
                ))}

                <section className="overflow-x-auto rounded-[10px] border border-border bg-card">
                    <div
                        className="min-w-[1000px] select-none"
                        onPointerUp={finishSelection}
                        onPointerLeave={() => setSelecting(null)}
                    >
                        <div className="grid grid-cols-[200px_repeat(14,minmax(0,1fr))] border-b border-border text-xs text-muted-foreground">
                            <div className="px-3.5 py-2.5">Unit</div>
                            {dayList.map((day) => {
                                const d = new Date(`${day}T12:00:00Z`);

                                return (
                                    <div
                                        key={day}
                                        className={cn(
                                            'border-l border-secondary px-1 py-2 text-center',
                                            day === today && 'bg-accent-soft',
                                        )}
                                    >
                                        <div>
                                            {d.toLocaleDateString('en-CA', {
                                                weekday: 'short',
                                                timeZone: 'UTC',
                                            })}
                                        </div>
                                        <div className="figure text-foreground">
                                            {d.getUTCDate()}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {units.length === 0 && (
                            <p className="p-8 text-center text-muted-foreground">
                                Add rental units to the fleet to see them here.
                            </p>
                        )}

                        {units.map((unit) => (
                            <div
                                key={unit.id}
                                className="grid min-h-[52px] grid-cols-[200px_repeat(14,minmax(0,1fr))] items-center border-b border-secondary"
                            >
                                <div className="col-start-1 row-start-1 px-3.5 py-2">
                                    <div className="text-[13px] font-semibold">
                                        {unit.name}
                                    </div>
                                    <div className="figure text-xs text-muted-foreground">
                                        {unit.serial}
                                        {unit.service_due && (
                                            <span className="ml-1.5 text-[#a16207]">
                                                service due
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {dayList.map((day, i) => {
                                    const key = `${unit.id}:${i}`;
                                    const inSelection =
                                        selecting?.unit.id === unit.id &&
                                        i >=
                                            Math.min(
                                                selecting.start,
                                                selecting.end,
                                            ) &&
                                        i <=
                                            Math.max(
                                                selecting.start,
                                                selecting.end,
                                            );

                                    return (
                                        <div
                                            key={day}
                                            style={{
                                                gridColumn: i + 2,
                                                gridRow: 1,
                                            }}
                                            className={cn(
                                                'h-full min-h-[52px] border-l border-secondary',
                                                dropTarget === key &&
                                                    'bg-accent-soft',
                                                inSelection && 'bg-info-soft',
                                            )}
                                            onDragOver={(e) => {
                                                if (dragging) {
                                                    e.preventDefault();
                                                    setDropTarget(key);
                                                }
                                            }}
                                            onDragLeave={() =>
                                                setDropTarget(null)
                                            }
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                setDropTarget(null);
                                                moveTo(unit, i);
                                                setDragging(null);
                                            }}
                                            onPointerDown={(e) => {
                                                if (
                                                    e.button === 0 &&
                                                    !dragging
                                                ) {
                                                    setSelecting({
                                                        unit,
                                                        start: i,
                                                        end: i,
                                                    });
                                                }
                                            }}
                                            onPointerEnter={() => {
                                                if (
                                                    selecting?.unit.id ===
                                                    unit.id
                                                ) {
                                                    setSelecting({
                                                        ...selecting,
                                                        end: i,
                                                    });
                                                }
                                            }}
                                        />
                                    );
                                })}

                                {unit.bars.map((bar) => {
                                    const cols = columns(bar);

                                    if (!cols) {
                                        return null;
                                    }

                                    const className = cn(
                                        'relative z-10 mx-0.5 flex h-8 items-center overflow-hidden rounded-md px-2 text-xs font-semibold whitespace-nowrap',
                                        barStyles[bar.kind],
                                        bar.movable &&
                                            'cursor-grab active:cursor-grabbing',
                                    );
                                    const style = {
                                        gridRow: 1,
                                        gridColumn: `${cols.start} / span ${cols.span}`,
                                    };

                                    return bar.rental_id ? (
                                        <Link
                                            key={`${bar.kind}-${bar.rental_item_id}`}
                                            href={RentalController.show(
                                                bar.rental_id,
                                            )}
                                            style={style}
                                            className={className}
                                            title={`${bar.number} · ${bar.label}`}
                                            draggable={bar.movable}
                                            onPointerDown={(e) =>
                                                e.stopPropagation()
                                            }
                                            onDragStart={(e) => {
                                                e.dataTransfer.effectAllowed =
                                                    'move';
                                                setDragging(bar);
                                            }}
                                            onDragEnd={() => setDragging(null)}
                                        >
                                            {bar.label}
                                        </Link>
                                    ) : (
                                        <div
                                            key={`service-${bar.label}`}
                                            style={style}
                                            className={className}
                                            title={bar.label}
                                        >
                                            {bar.label}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </section>
            </PageBody>
        </>
    );
}

Calendar.layout = {
    breadcrumbs: [{ title: 'Calendar', href: CalendarController.url() }],
};
