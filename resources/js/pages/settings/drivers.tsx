import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import DriverAccountsController from '@/actions/App/Http/Controllers/Settings/DriverAccountsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Field } from '@/components/yard/field';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type Driver = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    joined: boolean;
    /** Trucks they're able to drive. */
    truck_ids: number[];
    last_seen: string | null;
};

type Truck = { id: number; name: string; is_active: boolean };

type Props = {
    drivers: Driver[];
    trucks: Truck[];
};

/**
 * Tick every truck a driver is able to drive. Pills rather than a native
 * multi-select, which is fiddly on the yard's tablets.
 */
function TruckPicker({
    id,
    trucks,
    value,
    onChange,
    error,
}: {
    id: string;
    trucks: Truck[];
    value: number[];
    onChange: (ids: number[]) => void;
    error?: string;
}) {
    return (
        <fieldset className="grid min-w-0 gap-1.5">
            <legend className="mb-1.5 text-sm leading-none font-medium">
                Can drive
            </legend>
            {trucks.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No trucks yet. Add them under Delivery → Trucks.
                </p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {trucks.map((t) => {
                        const checked = value.includes(t.id);

                        return (
                            <label
                                key={t.id}
                                htmlFor={`${id}-${t.id}`}
                                className={cn(
                                    'flex h-10 cursor-pointer items-center gap-2 rounded-lg border bg-card px-3 text-sm transition-colors has-focus-visible:ring-[3px] has-focus-visible:ring-ring/30',
                                    checked
                                        ? 'border-primary bg-accent-soft font-semibold'
                                        : 'border-input hover:bg-accent',
                                )}
                            >
                                <input
                                    id={`${id}-${t.id}`}
                                    type="checkbox"
                                    className="size-4 accent-foreground"
                                    checked={checked}
                                    onChange={(e) =>
                                        onChange(
                                            e.target.checked
                                                ? [...value, t.id]
                                                : value.filter(
                                                      (v) => v !== t.id,
                                                  ),
                                        )
                                    }
                                />
                                {t.name}
                                {!t.is_active && (
                                    <span className="font-normal text-muted-foreground">
                                        (off the board)
                                    </span>
                                )}
                            </label>
                        );
                    })}
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                Dispatch picks who drives each truck each day, from the drivers
                ticked for it.
            </p>
            <InputError message={error} />
        </fieldset>
    );
}

function EditDriver({
    driver,
    trucks,
    onDone,
}: {
    driver: Driver;
    trucks: Truck[];
    onDone: () => void;
}) {
    const form = useForm({
        phone: driver.phone ?? '',
        truck_ids: driver.truck_ids,
    });

    return (
        <form
            className="grid w-full gap-3 rounded-lg bg-muted/40 p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.put(DriverAccountsController.update.url(driver.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <Field
                label="Phone"
                htmlFor={`driver-${driver.id}-phone`}
                error={form.errors.phone}
                className="sm:max-w-xs"
            >
                <Input
                    id={`driver-${driver.id}-phone`}
                    type="tel"
                    autoComplete="off"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                />
            </Field>
            <TruckPicker
                id={`driver-${driver.id}-trucks`}
                trucks={trucks}
                value={form.data.truck_ids}
                onChange={(ids) => form.setData('truck_ids', ids)}
                error={form.errors.truck_ids}
            />
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Save
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onDone}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function truckSummary(driver: Driver, trucks: Truck[]): string {
    const names = trucks
        .filter((t) => driver.truck_ids.includes(t.id))
        .map((t) => t.name);

    return names.length > 0
        ? `Can drive ${names.join(', ')}`
        : 'No trucks ticked yet';
}

function AddDriver({ trucks }: Pick<Props, 'trucks'>) {
    const form = useForm<{
        name: string;
        email: string;
        phone: string;
        truck_ids: number[];
    }>({
        name: '',
        email: '',
        phone: '',
        truck_ids: [],
    });

    return (
        <form
            className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-6 sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(DriverAccountsController.store.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Field
                label="Name"
                htmlFor="driver-name"
                error={form.errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id="driver-name"
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="Sam Rinaldi"
                />
            </Field>
            <Field
                label="Email"
                htmlFor="driver-email"
                error={form.errors.email}
                className="sm:col-span-2"
            >
                <Input
                    id="driver-email"
                    type="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </Field>
            <Field
                label="Phone"
                htmlFor="driver-phone"
                error={form.errors.phone}
                className="sm:col-span-2"
            >
                <Input
                    id="driver-phone"
                    type="tel"
                    autoComplete="off"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                    placeholder="905-555-0123"
                />
            </Field>
            <div className="sm:col-span-6">
                <TruckPicker
                    id="driver-new-trucks"
                    trucks={trucks}
                    value={form.data.truck_ids}
                    onChange={(ids) => form.setData('truck_ids', ids)}
                    error={form.errors.truck_ids}
                />
            </div>
            <div className="sm:col-span-6">
                <Button type="submit" disabled={form.processing}>
                    Add driver
                </Button>
            </div>
        </form>
    );
}

export default function DriversSettings({ drivers, trucks }: Props) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title="Drivers" />
            <h1 className="sr-only">Drivers</h1>

            <div className="space-y-10">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Drivers"
                        description="Drivers sign in on their phone and see the run of the truck dispatch puts them on that day: stops in order, directions, proof of delivery. While their run is open, their phone shares its location with the dispatch board."
                    />

                    {drivers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No drivers sign in yet. Add one below and they'll
                            get an email to choose a password.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {drivers.map((d) => (
                                <li
                                    key={d.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-3"
                                >
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2 font-medium">
                                            {d.name}
                                            {d.joined ? (
                                                <Chip tone="success">
                                                    Signed in
                                                </Chip>
                                            ) : (
                                                <Chip tone="warning">
                                                    Invited
                                                </Chip>
                                            )}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {[d.email, d.phone]
                                                .filter(Boolean)
                                                .join(' · ')}
                                            {' · '}
                                            {truckSummary(d, trucks)}
                                        </div>
                                        {d.last_seen && (
                                            <div className="text-xs text-muted-foreground">
                                                Location last shared{' '}
                                                {formatDateTime(d.last_seen)}
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex gap-2">
                                        {editing !== d.id && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setEditing(d.id)}
                                            >
                                                Edit
                                            </Button>
                                        )}
                                        {!d.joined && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        DriverAccountsController.resend.url(
                                                            d.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Send link again
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        `Remove ${d.name}? They won't be able to sign in.`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        DriverAccountsController.destroy.url(
                                                            d.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                    {editing === d.id && (
                                        <EditDriver
                                            driver={d}
                                            trucks={trucks}
                                            onDone={() => setEditing(null)}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    <AddDriver trucks={trucks} />
                </section>
            </div>
        </>
    );
}
