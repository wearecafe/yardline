import { Head, router, useForm } from '@inertiajs/react';
import DriverAccountsController from '@/actions/App/Http/Controllers/Settings/DriverAccountsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Field, NativeSelect } from '@/components/yard/field';
import { formatDateTime } from '@/lib/format';

type Driver = {
    id: number;
    name: string;
    email: string;
    joined: boolean;
    trucks: string[];
    last_seen: string | null;
};

type Props = {
    drivers: Driver[];
    trucks: { id: number; name: string; driver_id: number | null }[];
};

function AddDriver({ trucks }: Pick<Props, 'trucks'>) {
    const form = useForm({ name: '', email: '', truck_id: '' });

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
                label="Drives"
                htmlFor="driver-truck"
                error={form.errors.truck_id}
            >
                <NativeSelect
                    id="driver-truck"
                    value={form.data.truck_id}
                    onChange={(e) => form.setData('truck_id', e.target.value)}
                >
                    <option value="">No truck yet</option>
                    {trucks.map((t) => (
                        <option key={t.id} value={String(t.id)}>
                            {t.name}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Button type="submit" disabled={form.processing}>
                Add driver
            </Button>
        </form>
    );
}

export default function DriversSettings({ drivers, trucks }: Props) {
    return (
        <>
            <Head title="Drivers" />
            <h1 className="sr-only">Drivers</h1>

            <div className="space-y-10">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Drivers"
                        description="Drivers sign in on their phone and see only their own truck's run: stops in order, directions, proof of delivery. While their run is open, their phone shares its location with the dispatch board."
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
                                            {d.email}
                                            {' · '}
                                            {d.trucks.length > 0
                                                ? d.trucks.join(', ')
                                                : 'No truck. Pick one under Delivery → Trucks.'}
                                        </div>
                                        {d.last_seen && (
                                            <div className="text-xs text-muted-foreground">
                                                Location last shared{' '}
                                                {formatDateTime(d.last_seen)}
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex gap-2">
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
