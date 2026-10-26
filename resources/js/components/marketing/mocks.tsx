import type { ReactNode } from 'react';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { cn } from '@/lib/utils';

/*
 * Illustrations for the marketing page, drawn with the app's own pieces so
 * they look like the product. All the figures are made up.
 */

export function Frame({
    title,
    meta,
    children,
    className,
}: {
    title: string;
    meta?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-lg border border-border bg-card shadow-sm',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                <span className="truncate text-sm font-semibold">{title}</span>
                {meta && (
                    <span className="shrink-0 font-mono text-xs text-muted-foreground">
                        {meta}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

const queue: {
    number: string;
    who: string;
    what: string;
    tone: ChipTone;
    status: string;
}[] = [
    {
        number: 'SO-1042',
        who: 'Brantwood Landscaping',
        what: '1,200 sq ft charcoal paver · 3 t screenings',
        tone: 'info',
        status: 'Picking',
    },
    {
        number: 'SO-1043',
        who: 'Halton Masonry',
        what: '80 bags Type S · 2 pallets brick',
        tone: 'success',
        status: 'Loaded',
    },
    {
        number: 'RN-0311',
        who: 'Dana Kowalski',
        what: 'Plate compactor · 2 days',
        tone: 'warning',
        status: 'Due back 4 pm',
    },
    {
        number: 'SO-1044',
        who: 'Kerncliff Homes',
        what: '14 yd triple mix · bulk bags',
        tone: 'neutral',
        status: 'Unscheduled',
    },
];

export function QueueMock() {
    return (
        <Frame title="Today · Waterdown yard" meta="4 open">
            <ul className="divide-y divide-border">
                {queue.map((row) => (
                    <li key={row.number} className="grid gap-1 px-4 py-3">
                        <div className="flex items-center justify-between gap-3">
                            <span className="truncate text-sm font-medium">
                                <span className="font-mono text-muted-foreground">
                                    {row.number}
                                </span>{' '}
                                {row.who}
                            </span>
                            <Chip tone={row.tone}>{row.status}</Chip>
                        </div>
                        <span className="truncate text-sm text-muted-foreground">
                            {row.what}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="grid grid-cols-3 border-t border-border text-center">
                <Figure value="$18,420" label="Sold today" />
                <Figure value="6" label="Deliveries" />
                <Figure value="3" label="Units out" />
            </div>
        </Frame>
    );
}

function Figure({ value, label }: { value: string; label: string }) {
    return (
        <div className="px-2 py-3">
            <div className="font-mono text-base font-semibold">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

export function PricingMock() {
    const rows: {
        label: string;
        source: string;
        price: string;
        active?: boolean;
    }[] = [
        {
            label: 'Contract · Brantwood, spring jobs',
            source: 'until Jun 30',
            price: '$4.85',
            active: true,
        },
        {
            label: 'Contractor price list · 500+ sq ft',
            source: 'break',
            price: '$5.10',
        },
        { label: 'Retail', source: 'list', price: '$5.45' },
    ];

    return (
        <Frame title="Paver, 60 mm, charcoal" meta="per sq ft">
            <div className="grid gap-4 p-4">
                <div className="grid grid-cols-3 gap-2 text-center">
                    {[
                        ['Square foot', '1'],
                        ['Layer', '11.5'],
                        ['Pallet', '115'],
                    ].map(([unit, factor]) => (
                        <div
                            key={unit}
                            className="rounded-md border border-border px-2 py-2"
                        >
                            <div className="text-xs text-muted-foreground">
                                {unit}
                            </div>
                            <div className="font-mono text-sm font-semibold">
                                × {factor}
                            </div>
                        </div>
                    ))}
                </div>
                <ul className="grid gap-1.5">
                    {rows.map((row) => (
                        <li
                            key={row.label}
                            className={cn(
                                'flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm',
                                row.active
                                    ? 'bg-accent-soft font-medium'
                                    : 'text-muted-foreground line-through decoration-border',
                            )}
                        >
                            <span className="truncate">{row.label}</span>
                            <span className="shrink-0 font-mono">
                                {row.price}
                            </span>
                        </li>
                    ))}
                </ul>
                <div className="flex items-center justify-between border-t border-border pt-3 text-sm">
                    <span>
                        <span className="font-mono">12</span> pallets →{' '}
                        <span className="font-mono">1,380</span> sq ft
                    </span>
                    <span className="font-mono font-semibold">$6,693.00</span>
                </div>
            </div>
        </Frame>
    );
}

const hours = ['7', '8', '9', '10', '11', '12', '1', '2', '3'];

const trucks: {
    name: string;
    note: string;
    stops: { start: number; span: number; label: string; tone: string }[];
}[] = [
    {
        name: 'Boom 1',
        note: '12 t · boom',
        stops: [
            {
                start: 0,
                span: 2,
                label: 'SO-1043',
                tone: 'bg-success-soft text-success',
            },
            {
                start: 3,
                span: 2.5,
                label: 'SO-1047',
                tone: 'bg-info-soft text-info',
            },
        ],
    },
    {
        name: 'Tandem 2',
        note: '16 t',
        stops: [
            {
                start: 1,
                span: 2,
                label: 'SO-1044',
                tone: 'bg-info-soft text-info',
            },
            {
                start: 5,
                span: 1.5,
                label: 'Pallets',
                tone: 'bg-secondary text-subtle',
            },
        ],
    },
    {
        name: 'Float',
        note: 'rentals',
        stops: [
            {
                start: 0,
                span: 1.5,
                label: 'Drop',
                tone: 'bg-warning-soft text-warning',
            },
            {
                start: 6,
                span: 1.5,
                label: 'Pickup',
                tone: 'bg-warning-soft text-warning',
            },
        ],
    },
];

export function DispatchMock() {
    return (
        <Frame title="Dispatch · Tuesday" meta="3 trucks · 6 stops">
            <div className="overflow-x-auto">
                <div className="min-w-[30rem] p-3">
                    <div className="grid grid-cols-[5.5rem_repeat(9,minmax(0,1fr))] text-center font-mono text-[11px] text-muted-foreground">
                        <span />
                        {hours.map((h) => (
                            <span key={h}>{h}</span>
                        ))}
                    </div>
                    <div className="mt-1 grid gap-1.5">
                        {trucks.map((truck) => (
                            <div
                                key={truck.name}
                                className="grid grid-cols-[5.5rem_repeat(9,minmax(0,1fr))] items-center gap-y-1"
                            >
                                <div className="pr-2">
                                    <div className="text-xs font-semibold">
                                        {truck.name}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">
                                        {truck.note}
                                    </div>
                                </div>
                                <div className="relative col-span-9 h-9 rounded-md bg-background">
                                    {truck.stops.map((stop) => (
                                        <div
                                            key={stop.label}
                                            className={cn(
                                                'absolute inset-y-1 truncate rounded px-1.5 text-[11px] leading-7 font-semibold',
                                                stop.tone,
                                            )}
                                            style={{
                                                left: `${(stop.start / 9) * 100}%`,
                                                width: `calc(${(stop.span / 9) * 100}% - 4px)`,
                                            }}
                                        >
                                            {stop.label}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            <div className="flex items-center justify-between gap-3 border-t border-border px-4 py-3 text-sm">
                <span className="text-muted-foreground">
                    Unscheduled: SO-1048, pallet pickup · Halton
                </span>
                <Chip tone="info">ETA texts sent</Chip>
            </div>
        </Frame>
    );
}

export function RentalMock() {
    return (
        <Frame title="Plate compactor, 200 lb" meta="Unit PC-04">
            <div className="grid gap-3 p-4 text-sm">
                <div className="grid grid-cols-2 gap-2">
                    <div className="rounded-md border border-border px-3 py-2">
                        <div className="text-xs text-muted-foreground">Out</div>
                        <div className="font-medium">Fri 3:00 pm</div>
                    </div>
                    <div className="rounded-md border border-border px-3 py-2">
                        <div className="text-xs text-muted-foreground">
                            Back
                        </div>
                        <div className="font-medium">Mon 8:00 am</div>
                    </div>
                </div>
                <ul className="grid gap-1 text-muted-foreground">
                    <li className="flex justify-between">
                        <span>3 × daily at $65</span>
                        <span className="font-mono line-through">$195.00</span>
                    </li>
                    <li className="flex justify-between font-medium text-foreground">
                        <span>Weekend rate</span>
                        <span className="font-mono">$120.00</span>
                    </li>
                    <li className="flex justify-between">
                        <span>Deposit, refunded on return</span>
                        <span className="font-mono">$200.00</span>
                    </li>
                </ul>
                <div className="flex flex-wrap gap-1.5 border-t border-border pt-3">
                    <Chip tone="success">Agreement signed</Chip>
                    <Chip tone="neutral">Hours 412.6</Chip>
                    <Chip tone="warning">Service in 14 h</Chip>
                </div>
            </div>
        </Frame>
    );
}

export function PortalMock() {
    return (
        <div className="mx-auto w-full max-w-[18rem] rounded-[2rem] border-8 border-foreground bg-background shadow-lg">
            <div className="grid gap-3 p-4">
                <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold">
                        Escarpment Stone
                    </span>
                    <span className="text-xs text-muted-foreground">
                        Brantwood
                    </span>
                </div>
                <div className="rounded-lg border border-border bg-card p-3">
                    <div className="text-xs text-muted-foreground">
                        On its way
                    </div>
                    <div className="mt-0.5 text-sm font-semibold">
                        SO-1042 · ETA 10:40 am
                    </div>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-secondary">
                        <div className="h-full w-2/3 rounded-full bg-primary" />
                    </div>
                </div>
                <div className="rounded-lg border border-border bg-card p-3">
                    <div className="text-xs text-muted-foreground">
                        Patio calculator
                    </div>
                    <div className="mt-1 font-mono text-sm">
                        <span className="font-semibold">400</span> sq ft
                    </div>
                    <ul className="mt-2 grid gap-1 text-xs">
                        <li className="flex justify-between">
                            <span>Paver, charcoal</span>
                            <span className="font-mono">4 pallets</span>
                        </li>
                        <li className="flex justify-between">
                            <span>Granular A base</span>
                            <span className="font-mono">9.6 t</span>
                        </li>
                        <li className="flex justify-between">
                            <span>Polymeric sand</span>
                            <span className="font-mono">6 bags</span>
                        </li>
                    </ul>
                    <div className="mt-3 rounded-md bg-primary py-2 text-center text-xs font-semibold text-primary-foreground">
                        Send to the yard
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-2 text-center text-xs">
                    <div className="rounded-lg border border-border bg-card py-2">
                        <div className="font-mono text-sm font-semibold">
                            $4,212
                        </div>
                        Balance
                    </div>
                    <div className="rounded-lg border border-border bg-card py-2">
                        <div className="font-mono text-sm font-semibold">
                            12
                        </div>
                        Pallets out
                    </div>
                </div>
            </div>
        </div>
    );
}

export function AgingMock() {
    const buckets: {
        label: string;
        value: string;
        width: string;
        tone: string;
    }[] = [
        {
            label: 'Current',
            value: '$48,210',
            width: 'w-full',
            tone: 'bg-success',
        },
        { label: '1–30', value: '$21,940', width: 'w-[46%]', tone: 'bg-info' },
        {
            label: '31–60',
            value: '$8,305',
            width: 'w-[18%]',
            tone: 'bg-warning',
        },
        { label: '61–90', value: '$2,180', width: 'w-[6%]', tone: 'bg-danger' },
        { label: '90+', value: '$640', width: 'w-[2%]', tone: 'bg-danger' },
    ];

    return (
        <Frame title="Receivables" meta="all yards">
            <ul className="grid gap-2.5 p-4">
                {buckets.map((b) => (
                    <li
                        key={b.label}
                        className="grid grid-cols-[3.5rem_1fr_5rem] items-center gap-3 text-sm"
                    >
                        <span className="text-muted-foreground">{b.label}</span>
                        <span className="h-2 overflow-hidden rounded-full bg-secondary">
                            <span
                                className={cn(
                                    'block h-full rounded-full',
                                    b.width,
                                    b.tone,
                                )}
                            />
                        </span>
                        <span className="text-right font-mono">{b.value}</span>
                    </li>
                ))}
            </ul>
            <div className="grid grid-cols-2 border-t border-border text-sm">
                <div className="border-r border-border px-4 py-3">
                    <div className="text-xs text-muted-foreground">
                        Pallets out
                    </div>
                    <div className="font-mono font-semibold">214 · $3,210</div>
                </div>
                <div className="px-4 py-3">
                    <div className="text-xs text-muted-foreground">
                        Open credits
                    </div>
                    <div className="font-mono font-semibold">$1,486.20</div>
                </div>
            </div>
        </Frame>
    );
}

export function StockMock() {
    const rows: {
        name: string;
        run: string;
        waterdown: string;
        stoney: string;
        tone: ChipTone;
        status: string;
    }[] = [
        {
            name: 'Paver, 60 mm, charcoal',
            run: 'Run 2611-B',
            waterdown: '1,640',
            stoney: '920',
            tone: 'success',
            status: 'OK',
        },
        {
            name: 'Retaining wall cap',
            run: 'Run 2604-A',
            waterdown: '252',
            stoney: '40',
            tone: 'warning',
            status: 'Low',
        },
        {
            name: 'Type S mortar',
            run: '—',
            waterdown: '310',
            stoney: '180',
            tone: 'success',
            status: 'OK',
        },
        {
            name: 'Screenings',
            run: '—',
            waterdown: '38 t',
            stoney: '0 t',
            tone: 'danger',
            status: 'Reorder',
        },
    ];

    return (
        <Frame title="Stock" meta="2 yards">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[26rem] text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th className="px-4 py-2 font-medium">Product</th>
                            <th className="px-2 py-2 text-right font-medium">
                                Waterdown
                            </th>
                            <th className="px-2 py-2 text-right font-medium">
                                Stoney Creek
                            </th>
                            <th className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {rows.map((r) => (
                            <tr key={r.name}>
                                <td className="px-4 py-2.5">
                                    <div className="font-medium">{r.name}</div>
                                    <div className="font-mono text-xs text-muted-foreground">
                                        {r.run}
                                    </div>
                                </td>
                                <td className="px-2 py-2.5 text-right font-mono">
                                    {r.waterdown}
                                </td>
                                <td className="px-2 py-2.5 text-right font-mono">
                                    {r.stoney}
                                </td>
                                <td className="px-4 py-2.5 text-right">
                                    <Chip tone={r.tone}>{r.status}</Chip>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Frame>
    );
}
