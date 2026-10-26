import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ReturnController from '@/actions/App/Http/Controllers/ReturnController';
import DeliverySettingsController from '@/actions/App/Http/Controllers/Settings/DeliverySettingsController';
import Heading from '@/components/heading';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { ZoneRow } from '@/components/yard/zone-row';
import type { Zone } from '@/components/yard/zone-row';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { centsToInput } from '@/lib/format';

type Settings = {
    method: 'zone' | 'distance' | 'truckload';
    base_cents: number;
    per_km_cents: number;
    per_load_cents: number;
    load_capacity_kg: number;
    out_of_zone_cents: number;
    eta_texts: boolean;
};

type Truck = {
    id: number;
    name: string;
    kind: 'material' | 'equipment';
    driver_name: string | null;
    driver_phone: string | null;
    capacity_kg: number | null;
    has_boom: boolean;
    is_active: boolean;
    location_id: number | null;
};

type Props = {
    settings: Settings;
    methods: { value: Settings['method']; label: string }[];
    zones: Zone[];
    trucks: Truck[];
    yards: { id: number; name: string; pallet_deposits: boolean }[];
    returns: {
        restocking_percent: number;
        pallet_deposits: boolean;
        pallet_deposit_cents: number;
        return_window_days: number;
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
    });

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.put(ReturnController.settings.url(), {
                    preserveScroll: true,
                });
            }}
        >
            <Heading
                variant="small"
                title="Returns and pallet deposits"
                description="The restocking fee on returned material, and the deposit charged on each pallet."
            />
            <div className="grid gap-4 sm:grid-cols-3">
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
                            form.setData('restocking_percent', e.target.value)
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
                            form.setData('return_window_days', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Pallet deposit"
                    htmlFor="pallet_deposit"
                    error={form.errors.pallet_deposit}
                >
                    <Input
                        id="pallet_deposit"
                        inputMode="decimal"
                        value={form.data.pallet_deposit}
                        onChange={(e) =>
                            form.setData('pallet_deposit', e.target.value)
                        }
                    />
                </Field>
            </div>
            <Checkbox
                id="pallet_deposits"
                label="Charge pallet deposits"
                checked={form.data.pallet_deposits}
                onChange={(v) => form.setData('pallet_deposits', v)}
            />
            {form.data.pallet_deposits && (
                <div className="flex flex-wrap gap-5 pl-6">
                    {yards.map((y) => (
                        <Checkbox
                            key={y.id}
                            id={`yard-pallets-${y.id}`}
                            label={y.name}
                            checked={form.data.yards[String(y.id)] ?? true}
                            onChange={(v) =>
                                form.setData('yards', {
                                    ...form.data.yards,
                                    [String(y.id)]: v,
                                })
                            }
                        />
                    ))}
                </div>
            )}
            <Button type="submit" variant="outline" disabled={form.processing}>
                Save returns and deposits
            </Button>
        </form>
    );
}

function TruckRow({ truck }: { truck: Truck | null }) {
    const id = truck?.id ?? 'new';
    const form = useForm({
        name: truck?.name ?? '',
        kind: truck?.kind ?? 'material',
        driver_name: truck?.driver_name ?? '',
        driver_phone: truck?.driver_phone ?? '',
        capacity_t: truck?.capacity_kg ? String(truck.capacity_kg / 1000) : '',
        has_boom: truck?.has_boom ?? false,
        is_active: truck?.is_active ?? true,
    });
    const [removing, setRemoving] = useState(false);

    return (
        <form
            className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-6 sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();

                if (truck) {
                    form.put(
                        DeliverySettingsController.updateTruck.url(truck.id),
                        { preserveScroll: true },
                    );
                } else {
                    form.post(DeliverySettingsController.storeTruck.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
        >
            <Field
                label="Truck"
                htmlFor={`truck-name-${id}`}
                error={form.errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id={`truck-name-${id}`}
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="Truck 1 · tandem"
                />
            </Field>
            <Field label="Carries" htmlFor={`truck-kind-${id}`}>
                <NativeSelect
                    id={`truck-kind-${id}`}
                    value={form.data.kind}
                    onChange={(e) =>
                        form.setData('kind', e.target.value as Truck['kind'])
                    }
                >
                    <option value="material">Material</option>
                    <option value="equipment">Rental equipment</option>
                </NativeSelect>
            </Field>
            <Field
                label="Capacity (t)"
                htmlFor={`truck-cap-${id}`}
                error={form.errors.capacity_t}
            >
                <Input
                    id={`truck-cap-${id}`}
                    inputMode="decimal"
                    value={form.data.capacity_t}
                    onChange={(e) => form.setData('capacity_t', e.target.value)}
                />
            </Field>
            <Field label="Driver" htmlFor={`truck-driver-${id}`}>
                <Input
                    id={`truck-driver-${id}`}
                    value={form.data.driver_name}
                    onChange={(e) =>
                        form.setData('driver_name', e.target.value)
                    }
                />
            </Field>
            <Field label="Driver's phone" htmlFor={`truck-phone-${id}`}>
                <Input
                    id={`truck-phone-${id}`}
                    type="tel"
                    value={form.data.driver_phone}
                    onChange={(e) =>
                        form.setData('driver_phone', e.target.value)
                    }
                />
            </Field>
            <div className="flex flex-wrap items-center gap-4 sm:col-span-4">
                <Checkbox
                    id={`truck-boom-${id}`}
                    label="Boom"
                    checked={form.data.has_boom}
                    onChange={(v) => form.setData('has_boom', v)}
                />
                {truck && (
                    <Checkbox
                        id={`truck-active-${id}`}
                        label="On the board"
                        checked={form.data.is_active}
                        onChange={(v) => form.setData('is_active', v)}
                    />
                )}
            </div>
            <div className="flex gap-2 sm:col-span-2 sm:justify-end">
                <Button
                    type="submit"
                    variant={truck ? 'outline' : 'default'}
                    disabled={form.processing}
                >
                    {truck ? 'Save' : 'Add truck'}
                </Button>
                {truck && (
                    <Button
                        type="button"
                        variant="ghost"
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
                        Remove
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function DeliverySettingsPage({
    settings,
    methods,
    zones,
    trucks,
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
    });

    return (
        <>
            <Head title="Delivery settings" />
            <h1 className="sr-only">Delivery settings</h1>

            <div className="space-y-10">
                <form
                    className="space-y-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(DeliverySettingsController.update.url(), {
                            preserveScroll: true,
                        });
                    }}
                >
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
                                        form.setData(
                                            'load_capacity_t',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    )}

                    <Checkbox
                        id="eta_texts"
                        label="Text customers their delivery window"
                        hint="From the dispatch board, once each stop is on a truck."
                        checked={form.data.eta_texts}
                        onChange={(v) => form.setData('eta_texts', v)}
                    />

                    <Button type="submit" disabled={form.processing}>
                        Save delivery settings
                    </Button>
                </form>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Delivery zones"
                        description="Fees by postal code prefix, matched on the longest prefix. Used for material deliveries and rental drops."
                    />
                    {zones.map((zone) => (
                        <ZoneRow key={zone.id} zone={zone} />
                    ))}
                    <ZoneRow zone={null} />
                </section>

                <ReturnsForm returns={returns} yards={yards} />

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Trucks"
                        description="Each truck is a row on the dispatch board."
                    />
                    {trucks.map((truck) => (
                        <TruckRow key={truck.id} truck={truck} />
                    ))}
                    <TruckRow truck={null} />
                </section>
            </div>
        </>
    );
}
