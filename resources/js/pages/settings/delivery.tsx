import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import DeliverySettingsController from '@/actions/App/Http/Controllers/Settings/DeliverySettingsController';
import Heading from '@/components/heading';
import { Chip } from '@/components/yard/chip';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { Tabs } from '@/components/yard/tabs';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { ZoneRow } from '@/components/yard/zone-row';
import type { Zone } from '@/components/yard/zone-row';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { centsToInput } from '@/lib/format';
import { cn } from '@/lib/utils';

type Settings = {
    method: 'zone' | 'distance' | 'truckload';
    base_cents: number;
    per_km_cents: number;
    per_load_cents: number;
    load_capacity_kg: number;
    out_of_zone_cents: number;
    eta_texts: boolean;
    portal_stops_ahead: boolean;
    portal_truck_location: boolean;
};

type Truck = {
    id: number;
    name: string;
    kind: 'material' | 'equipment' | 'both';
    capacities: { unit: string; amount: number }[] | null;
    has_boom: boolean;
    is_active: boolean;
    location_id: number | null;
};

type CapacityUnit = { value: string; label: string };

type Props = {
    settings: Settings;
    methods: { value: Settings['method']; label: string }[];
    zones: Zone[];
    trucks: Truck[];
    capacityUnits: CapacityUnit[];
    yards: {
        id: number;
        name: string;
        pallet_deposits: boolean;
        pallet_usage_fees: boolean;
    }[];
    returns: {
        restocking_percent: number;
        pallet_deposits: boolean;
        pallet_deposit_cents: number;
        return_window_days: number;
        pallet_usage_fees: boolean;
        pallet_usage_fee_cents: number;
    };
};

function ReturnsForm({ returns, yards }: Pick<Props, 'returns' | 'yards'>) {
    const form = useForm({
        restocking_percent: String(returns.restocking_percent),
        pallet_deposits: returns.pallet_deposits,
        pallet_deposit: centsToInput(returns.pallet_deposit_cents),
        return_window_days: String(returns.return_window_days),
        yards: Object.fromEntries(
            yards.map((y) => [String(y.id), y.pallet_deposits]),
        ) as Record<string, boolean>,
        pallet_usage_fees: returns.pallet_usage_fees,
        pallet_usage_fee: centsToInput(returns.pallet_usage_fee_cents),
        fee_yards: Object.fromEntries(
            yards.map((y) => [String(y.id), y.pallet_usage_fees]),
        ) as Record<string, boolean>,
    });

    return (
        <form
            className="space-y-8"
            onSubmit={(e) => {
                e.preventDefault();
                form.put(ReturnController.settings.url(), {
                    preserveScroll: true,
                });
            }}
        >
            <section className="space-y-4">
                <Heading
                    variant="small"
                    title="Returns"
                    description="The restocking fee on returned material, and how long customers have to bring it back."
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Restocking fee (%)"
                        htmlFor="restocking_percent"
                        error={form.errors.restocking_percent}
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
                    <Field
                        label="Returns within (days)"
                        htmlFor="return_window_days"
                        error={form.errors.return_window_days}
                    >
                        <Input
                            id="return_window_days"
                            inputMode="numeric"
                            value={form.data.return_window_days}
                            onChange={(e) =>
                                form.setData(
                                    'return_window_days',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                </div>
            </section>

            <section className="space-y-4 border-t border-border pt-8">
                <Heading
                    variant="small"
                    title="Pallet deposits"
                    description="Charged on each pallet that leaves the yard, and credited when it comes back."
                />
                <Checkbox
                    id="pallet_deposits"
                    label="Charge pallet deposits"
                    checked={form.data.pallet_deposits}
                    onChange={(v) => form.setData('pallet_deposits', v)}
                />
                {form.data.pallet_deposits && (
                    <div className="space-y-4 pl-6">
                        <Field
                            label="Deposit per pallet"
                            htmlFor="pallet_deposit"
                            error={form.errors.pallet_deposit}
                            className="max-w-48"
                        >
                            <Input
                                id="pallet_deposit"
                                inputMode="decimal"
                                value={form.data.pallet_deposit}
                                onChange={(e) =>
                                    form.setData(
                                        'pallet_deposit',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        {yards.length > 1 && (
                            <fieldset className="space-y-2">
                                <legend className="mb-2 text-sm font-medium">
                                    At these yards
                                </legend>
                                <div className="flex flex-wrap gap-5">
                                    {yards.map((y) => (
                                        <Checkbox
                                            key={y.id}
                                            id={`yard-pallets-${y.id}`}
                                            label={y.name}
                                            checked={
                                                form.data.yards[String(y.id)] ??
                                                true
                                            }
                                            onChange={(v) =>
                                                form.setData('yards', {
                                                    ...form.data.yards,
                                                    [String(y.id)]: v,
                                                })
                                            }
                                        />
                                    ))}
                                </div>
                            </fieldset>
                        )}
                    </div>
                )}
            </section>

            <section className="space-y-4 border-t border-border pt-8">
                <Heading
                    variant="small"
                    title="Pallet usage fees"
                    description="A charge on each pallet that leaves the yard for the use of the pallet. Unlike a deposit, it isn't credited when the pallet comes back. It's taxed like the product, and only credited when unused product comes back on full pallets."
                />
                <Checkbox
                    id="pallet_usage_fees"
                    label="Charge pallet usage fees"
                    checked={form.data.pallet_usage_fees}
                    onChange={(v) => form.setData('pallet_usage_fees', v)}
                />
                {form.data.pallet_usage_fees && (
                    <div className="space-y-4 pl-6">
                        <Field
                            label="Usage fee per pallet"
                            htmlFor="pallet_usage_fee"
                            error={form.errors.pallet_usage_fee}
                            className="max-w-48"
                        >
                            <Input
                                id="pallet_usage_fee"
                                inputMode="decimal"
                                value={form.data.pallet_usage_fee}
                                onChange={(e) =>
                                    form.setData(
                                        'pallet_usage_fee',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <p className="text-sm text-muted-foreground">
                            Turn it on for each product that ships on a pallet
                            in the product editor.
                        </p>
                        {yards.length > 1 && (
                            <fieldset className="space-y-2">
                                <legend className="mb-2 text-sm font-medium">
                                    At these yards
                                </legend>
                                <div className="flex flex-wrap gap-5">
                                    {yards.map((y) => (
                                        <Checkbox
                                            key={y.id}
                                            id={`yard-pallet-fees-${y.id}`}
                                            label={y.name}
                                            checked={
                                                form.data.fee_yards[
                                                    String(y.id)
                                                ] ?? true
                                            }
                                            onChange={(v) =>
                                                form.setData('fee_yards', {
                                                    ...form.data.fee_yards,
                                                    [String(y.id)]: v,
                                                })
                                            }
                                        />
                                    ))}
                                </div>
                            </fieldset>
                        )}
                    </div>
                )}
            </section>

            <Button type="submit" disabled={form.processing}>
                Save returns, deposits and fees
            </Button>
        </form>
    );
}

const UNIT_NAMES: Record<string, string> = {
    t: 'tonnes (weight)',
    yd3: 'cubic yards',
    m3: 'cubic metres',
    pallet: 'pallets',
};

/**
 * One row per thing the truck can take: weight, volume or pallets. Each
 * unit at most once.
 */
function CapacityRows({
    id,
    units,
    rows,
    onChange,
    errors,
}: {
    id: string | number;
    units: CapacityUnit[];
    rows: { unit: string; amount: string }[];
    onChange: (rows: { unit: string; amount: string }[]) => void;
    errors: Record<string, string>;
}) {
    const unused = units.filter((u) => !rows.some((r) => r.unit === u.value));

    return (
        <fieldset className="grid min-w-0 gap-2 sm:col-span-2">
            <legend className="mb-1.5 text-sm leading-none font-medium">
                Capacity
            </legend>
            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No capacity set.
                </p>
            )}
            {rows.map((row, i) => (
                <div key={i} className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            id={`truck-cap-${id}-${i}`}
                            aria-label="Amount"
                            inputMode="decimal"
                            className="w-28"
                            value={row.amount}
                            onChange={(e) =>
                                onChange(
                                    rows.map((r, j) =>
                                        j === i
                                            ? { ...r, amount: e.target.value }
                                            : r,
                                    ),
                                )
                            }
                        />
                        <NativeSelect
                            aria-label="Unit"
                            className="w-40"
                            value={row.unit}
                            onChange={(e) =>
                                onChange(
                                    rows.map((r, j) =>
                                        j === i
                                            ? { ...r, unit: e.target.value }
                                            : r,
                                    ),
                                )
                            }
                        >
                            {units
                                .filter(
                                    (u) =>
                                        u.value === row.unit ||
                                        !rows.some((r) => r.unit === u.value),
                                )
                                .map((u) => (
                                    <option key={u.value} value={u.value}>
                                        {UNIT_NAMES[u.value] ?? u.label}
                                    </option>
                                ))}
                        </NativeSelect>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove this capacity"
                            onClick={() =>
                                onChange(rows.filter((_, j) => j !== i))
                            }
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                    <InputError
                        message={
                            errors[`capacities.${i}.amount`] ??
                            errors[`capacities.${i}.unit`]
                        }
                    />
                </div>
            ))}
            {unused.length > 0 && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onChange([
                                ...rows,
                                { unit: unused[0].value, amount: '' },
                            ])
                        }
                    >
                        <Plus className="size-4" />
                        Add capacity
                    </Button>
                </div>
            )}
        </fieldset>
    );
}

const KIND_LABELS: Record<Truck['kind'], string> = {
    material: 'Material',
    equipment: 'Rental equipment',
    both: 'Material and rentals',
};

/** "14 t · 12 cu yd", or null when no capacity is set. */
function capacitySummary(
    capacities: Truck['capacities'],
    units: CapacityUnit[],
): string | null {
    if (!capacities?.length) {
        return null;
    }

    return capacities
        .map(
            (c) =>
                `${Number(c.amount)} ${units.find((u) => u.value === c.unit)?.label ?? c.unit}`,
        )
        .join(' · ');
}

/**
 * The truck's details. A new truck posts; an existing one saves in place.
 */
function TruckForm({
    truck,
    units,
    onDone,
}: {
    truck: Truck | null;
    units: CapacityUnit[];
    onDone?: () => void;
}) {
    const id = truck?.id ?? 'new';
    const form = useForm<{
        name: string;
        kind: Truck['kind'];
        capacities: { unit: string; amount: string }[];
        has_boom: boolean;
        is_active: boolean;
    }>({
        name: truck?.name ?? '',
        kind: truck?.kind ?? 'material',
        capacities: (truck?.capacities ?? []).map((c) => ({
            unit: c.unit,
            amount: String(c.amount),
        })),
        has_boom: truck?.has_boom ?? false,
        is_active: truck?.is_active ?? true,
    });
    const [removing, setRemoving] = useState(false);

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();

                if (truck) {
                    form.put(
                        DeliverySettingsController.updateTruck.url(truck.id),
                        { preserveScroll: true, onSuccess: () => onDone?.() },
                    );
                } else {
                    form.post(DeliverySettingsController.storeTruck.url(), {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            onDone?.();
                        },
                    });
                }
            }}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Name"
                    htmlFor={`truck-name-${id}`}
                    error={form.errors.name}
                >
                    <Input
                        id={`truck-name-${id}`}
                        required
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Truck 1 · tandem"
                    />
                </Field>
                <Field
                    label="Carries"
                    htmlFor={`truck-kind-${id}`}
                    error={form.errors.kind}
                >
                    <NativeSelect
                        id={`truck-kind-${id}`}
                        value={form.data.kind}
                        onChange={(e) =>
                            form.setData(
                                'kind',
                                e.target.value as Truck['kind'],
                            )
                        }
                    >
                        <option value="material">Material</option>
                        <option value="equipment">Rental equipment</option>
                        <option value="both">Both</option>
                    </NativeSelect>
                </Field>
                <CapacityRows
                    id={id}
                    units={units}
                    rows={form.data.capacities}
                    onChange={(rows) => form.setData('capacities', rows)}
                    errors={form.errors as Record<string, string>}
                />
            </div>

            <div className="flex flex-wrap gap-5">
                <Checkbox
                    id={`truck-boom-${id}`}
                    label="Has a boom"
                    checked={form.data.has_boom}
                    onChange={(v) => form.setData('has_boom', v)}
                />
                {truck && (
                    <Checkbox
                        id={`truck-active-${id}`}
                        label="On the dispatch board"
                        checked={form.data.is_active}
                        onChange={(v) => form.setData('is_active', v)}
                    />
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" disabled={form.processing}>
                    {truck ? 'Save truck' : 'Add truck'}
                </Button>
                {onDone && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            form.reset();
                            form.clearErrors();
                            onDone();
                        }}
                    >
                        Cancel
                    </Button>
                )}
                {truck && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="ml-auto text-destructive hover:text-destructive"
                        disabled={removing}
                        onClick={() => {
                            setRemoving(true);
                            router.delete(
                                DeliverySettingsController.destroyTruck.url(
                                    truck.id,
                                ),
                                {
                                    preserveScroll: true,
                                    onFinish: () => setRemoving(false),
                                },
                            );
                        }}
                    >
                        Remove truck
                    </Button>
                )}
            </div>
        </form>
    );
}

/**
 * One truck in the list: a summary line that opens into its form.
 */
function TruckItem({ truck, units }: { truck: Truck; units: CapacityUnit[] }) {
    const [open, setOpen] = useState(false);
    const capacity = capacitySummary(truck.capacities, units);

    return (
        <li className={cn(!truck.is_active && 'bg-muted/40')}>
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-accent/50"
            >
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{truck.name}</span>
                        {!truck.is_active && <Chip>Off the board</Chip>}
                        {truck.has_boom && <Chip tone="info">Boom</Chip>}
                    </div>
                    <div className="truncate text-sm text-muted-foreground">
                        {KIND_LABELS[truck.kind]}
                        {' · '}
                        {capacity ?? 'No capacity set'}
                    </div>
                </div>
                <ChevronDown
                    className={cn(
                        'size-4 shrink-0 text-muted-foreground transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>
            {open && (
                <div className="border-t border-border px-4 py-4">
                    <TruckForm
                        truck={truck}
                        units={units}
                        onDone={() => setOpen(false)}
                    />
                </div>
            )}
        </li>
    );
}

function TrucksTab({
    trucks,
    units,
}: {
    trucks: Truck[];
    units: CapacityUnit[];
}) {
    const [adding, setAdding] = useState(trucks.length === 0);

    return (
        <section className="space-y-4">
            <Heading
                variant="small"
                title="Trucks"
                description="Each truck is a row on the dispatch board. Who drives it is picked there, day by day, from the drivers ticked for it under Settings → Drivers."
            />
            {trucks.length > 0 && (
                <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                    {trucks.map((truck) => (
                        <TruckItem key={truck.id} truck={truck} units={units} />
                    ))}
                </ul>
            )}
            {adding ? (
                <div className="space-y-4 rounded-lg border border-dashed border-input p-4">
                    <div className="font-medium">Add a truck</div>
                    <TruckForm
                        truck={null}
                        units={units}
                        onDone={
                            trucks.length > 0
                                ? () => setAdding(false)
                                : undefined
                        }
                    />
                </div>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setAdding(true)}
                >
                    <Plus className="size-4" />
                    Add a truck
                </Button>
            )}
        </section>
    );
}

export default function DeliverySettingsPage({
    settings,
    methods,
    zones,
    trucks,
    capacityUnits,
    yards,
    returns,
}: Props) {
    const form = useForm({
        method: settings.method,
        base: centsToInput(settings.base_cents),
        per_km: centsToInput(settings.per_km_cents),
        per_load: centsToInput(settings.per_load_cents),
        load_capacity_t: String(settings.load_capacity_kg / 1000),
        out_of_zone: settings.out_of_zone_cents
            ? centsToInput(settings.out_of_zone_cents)
            : '',
        eta_texts: settings.eta_texts,
        portal_stops_ahead: settings.portal_stops_ahead,
        portal_truck_location: settings.portal_truck_location,
    });

    const save = (e: FormEvent) => {
        e.preventDefault();
        form.put(DeliverySettingsController.update.url(), {
            preserveScroll: true,
        });
    };

    const fees = (
        <form className="space-y-6" onSubmit={save}>
            <Heading
                variant="small"
                title="Delivery fees"
                description="How material deliveries are charged. Staff can still set a fee by hand on any order."
            />
            <ToggleButtons
                label="Charge by"
                value={form.data.method}
                onChange={(v) => form.setData('method', v)}
                options={methods}
            />

            {form.data.method === 'zone' && (
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Each zone's fee is set on the Zones tab
                        {zones.length === 0
                            ? ', and you haven’t added any yet.'
                            : '.'}
                    </p>
                    <Field
                        label="Outside every zone"
                        htmlFor="out_of_zone"
                        error={form.errors.out_of_zone}
                        hint="Leave blank to set the fee by hand for addresses outside your zones."
                        className="max-w-xs"
                    >
                        <Input
                            id="out_of_zone"
                            inputMode="decimal"
                            value={form.data.out_of_zone}
                            onChange={(e) =>
                                form.setData('out_of_zone', e.target.value)
                            }
                        />
                    </Field>
                </div>
            )}
            {form.data.method === 'distance' && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Base fee"
                        htmlFor="base"
                        error={form.errors.base}
                    >
                        <Input
                            id="base"
                            inputMode="decimal"
                            value={form.data.base}
                            onChange={(e) =>
                                form.setData('base', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Per km from the yard"
                        htmlFor="per_km"
                        error={form.errors.per_km}
                        hint="Staff enter the distance on the order."
                    >
                        <Input
                            id="per_km"
                            inputMode="decimal"
                            value={form.data.per_km}
                            onChange={(e) =>
                                form.setData('per_km', e.target.value)
                            }
                        />
                    </Field>
                </div>
            )}
            {form.data.method === 'truckload' && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Per truckload"
                        htmlFor="per_load"
                        error={form.errors.per_load}
                    >
                        <Input
                            id="per_load"
                            inputMode="decimal"
                            value={form.data.per_load}
                            onChange={(e) =>
                                form.setData('per_load', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="A load is up to (t)"
                        htmlFor="load_capacity_t"
                        error={form.errors.load_capacity_t}
                        hint="Loads are worked out from product weights."
                    >
                        <Input
                            id="load_capacity_t"
                            inputMode="decimal"
                            value={form.data.load_capacity_t}
                            onChange={(e) =>
                                form.setData('load_capacity_t', e.target.value)
                            }
                        />
                    </Field>
                </div>
            )}

            <Button type="submit" disabled={form.processing}>
                Save delivery fees
            </Button>
        </form>
    );

    const updates = (
        <form className="space-y-8" onSubmit={save}>
            <section className="space-y-4">
                <Heading
                    variant="small"
                    title="Text messages"
                    description="Sent to the customer's phone on the order."
                />
                <Checkbox
                    id="eta_texts"
                    label="Text customers their delivery window"
                    hint="From the dispatch board, once each stop is on a truck."
                    checked={form.data.eta_texts}
                    onChange={(v) => form.setData('eta_texts', v)}
                />
            </section>

            <section className="space-y-4 border-t border-border pt-8">
                <Heading
                    variant="small"
                    title="Contractor portal"
                    description="What contractors see on an order while it's out for delivery."
                />
                <Checkbox
                    id="portal_stops_ahead"
                    label="Show how many stops are before theirs"
                    hint="On the order page while the delivery is on a truck today, for example “2 stops before yours”."
                    checked={form.data.portal_stops_ahead}
                    onChange={(v) => form.setData('portal_stops_ahead', v)}
                />
                <Checkbox
                    id="portal_truck_location"
                    label="Show the truck on a map when theirs is next"
                    hint="Uses the location the driver's phone shares. Only shown once their stop is the next one and the truck is on its way, so other customers' addresses stay private."
                    checked={form.data.portal_truck_location}
                    onChange={(v) => form.setData('portal_truck_location', v)}
                />
            </section>

            <Button type="submit" disabled={form.processing}>
                Save customer updates
            </Button>
        </form>
    );

    const zoneList = (
        <section className="space-y-4">
            <Heading
                variant="small"
                title="Delivery zones"
                description="Fees by postal code prefix, matched on the longest prefix. Used for rental drops, and for material deliveries when you charge by zone."
            />
            {zones.length > 0 && (
                <div className="space-y-2">
                    {zones.map((zone, i) => (
                        <ZoneRow
                            key={zone.id}
                            zone={zone}
                            showLabels={i === 0}
                        />
                    ))}
                </div>
            )}
            <div className="space-y-3 rounded-lg border border-dashed border-input p-4">
                <div className="font-medium">Add a zone</div>
                <ZoneRow zone={null} />
            </div>
        </section>
    );

    return (
        <>
            <Head title="Delivery settings" />
            <h1 className="sr-only">Delivery settings</h1>

            <Tabs
                label="Delivery settings"
                items={[
                    { value: 'fees', label: 'Fees', content: fees },
                    {
                        value: 'zones',
                        label: 'Zones',
                        count: zones.length,
                        content: zoneList,
                    },
                    {
                        value: 'trucks',
                        label: 'Trucks',
                        count: trucks.length,
                        content: (
                            <TrucksTab trucks={trucks} units={capacityUnits} />
                        ),
                    },
                    {
                        value: 'updates',
                        label: 'Customer updates',
                        content: updates,
                    },
                    {
                        value: 'returns',
                        label: 'Returns',
                        content: (
                            <ReturnsForm returns={returns} yards={yards} />
                        ),
                    },
                ]}
            />
        </>
    );
}
