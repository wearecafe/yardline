import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import UserAccountsController from '@/actions/App/Http/Controllers/Settings/UserAccountsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';

type Role = 'owner' | 'admin' | 'staff' | 'picker';

type Yard = { id: number; name: string };

type UserAccount = {
    id: number;
    name: string;
    email: string;
    role: Role;
    joined: boolean;
    is_you: boolean;
    manageable: boolean;
    yards: number[];
    has_pin: boolean;
};

type Props = {
    users: UserAccount[];
    roles: { value: Role; label: string }[];
    locations: Yard[];
};

const roleLabels: Record<Role, string> = {
    owner: 'Owner',
    admin: 'Admin',
    staff: 'Staff',
    picker: 'Order picker',
};

const digits = (value: string) => value.replace(/\D/g, '');

/** Ticks the yards an order picker works. */
function YardPicker({
    id,
    locations,
    value,
    onChange,
}: {
    id: string;
    locations: Yard[];
    value: number[];
    onChange: (ids: number[]) => void;
}) {
    return (
        <div className="flex flex-wrap gap-x-5 gap-y-2">
            {locations.map((yard) => (
                <Checkbox
                    key={yard.id}
                    id={`${id}-${yard.id}`}
                    label={yard.name}
                    checked={value.includes(yard.id)}
                    onChange={(on) =>
                        onChange(
                            on
                                ? [...value, yard.id]
                                : value.filter((v) => v !== yard.id),
                        )
                    }
                />
            ))}
        </div>
    );
}

/** An order picker's yards and the PIN they switch in with. */
function PickerAccess({
    user,
    locations,
}: {
    user: UserAccount;
    locations: Yard[];
}) {
    const [pin, setPin] = useState('');
    const update = (
        data: { location_ids?: number[]; pin?: string; clear_pin?: boolean },
        onSuccess?: () => void,
    ) =>
        router.patch(UserAccountsController.update.url(user.id), data, {
            preserveScroll: true,
            onSuccess,
        });

    return (
        <div className="grid w-full gap-3 rounded-md bg-secondary/50 p-3 text-sm">
            <div className="grid gap-1.5">
                <span className="font-medium">Yards they pick at</span>
                <YardPicker
                    id={`yards-${user.id}`}
                    locations={locations}
                    value={user.yards}
                    onChange={(ids) => {
                        // A picker always works at least one yard.
                        if (ids.length > 0) {
                            update({ location_ids: ids });
                        }
                    }}
                />
            </div>
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    update({ pin }, () => setPin(''));
                }}
            >
                <span className="font-medium">PIN</span>
                <span className="text-muted-foreground">
                    {user.has_pin ? 'Set' : 'None'}
                </span>
                <Input
                    aria-label={`New PIN for ${user.name}`}
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={6}
                    placeholder="4–6 digits"
                    className="h-8 w-28"
                    value={pin}
                    onChange={(e) => setPin(digits(e.target.value))}
                />
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    disabled={pin.length < 4}
                >
                    {user.has_pin ? 'Change PIN' : 'Set PIN'}
                </Button>
                {user.has_pin && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => update({ clear_pin: true })}
                    >
                        Remove PIN
                    </Button>
                )}
            </form>
        </div>
    );
}

function AddUser({ roles, locations }: Pick<Props, 'roles' | 'locations'>) {
    const form = useForm({
        name: '',
        email: '',
        role: 'staff' as Role,
        location_ids: locations.map((l) => l.id),
        pin: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-6 sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(UserAccountsController.store.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Field
                label="Name"
                htmlFor="user-name"
                error={form.errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id="user-name"
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="Jess Palmer"
                />
            </Field>
            <Field
                label="Email"
                htmlFor="user-email"
                error={form.errors.email}
                className="sm:col-span-2"
            >
                <Input
                    id="user-email"
                    type="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </Field>
            <Field label="Role" htmlFor="user-role" error={form.errors.role}>
                <NativeSelect
                    id="user-role"
                    value={form.data.role}
                    onChange={(e) =>
                        form.setData('role', e.target.value as Role)
                    }
                >
                    {roles.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Button type="submit" disabled={form.processing}>
                Add user
            </Button>
            {form.data.role === 'picker' && (
                <div className="grid gap-3 sm:col-span-6 sm:grid-cols-6">
                    <Field
                        label="Yards they pick at"
                        htmlFor="user-yards"
                        error={errors.location_ids}
                        className="sm:col-span-4"
                    >
                        <YardPicker
                            id="user-yards"
                            locations={locations}
                            value={form.data.location_ids}
                            onChange={(ids) =>
                                form.setData('location_ids', ids)
                            }
                        />
                    </Field>
                    <Field
                        label="PIN for a shared tablet (optional)"
                        htmlFor="user-pin"
                        error={form.errors.pin}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="user-pin"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={6}
                            placeholder="4–6 digits"
                            value={form.data.pin}
                            onChange={(e) =>
                                form.setData('pin', digits(e.target.value))
                            }
                        />
                    </Field>
                </div>
            )}
        </form>
    );
}

export default function UsersSettings({ users, roles, locations }: Props) {
    return (
        <>
            <Head title="Users" />
            <h1 className="sr-only">Users</h1>

            <div className="space-y-10">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Users"
                        description="People who sign in to the yard. Staff work orders, stock and rentals. Admins can also change business settings, credit terms and line prices, and override credit limits. Order pickers only see the order queue for their yards, with no prices or payments. Drivers are added under Drivers."
                    />

                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {users.map((u) => (
                            <li
                                key={u.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-3"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2 font-medium">
                                        {u.name}
                                        {u.is_you && (
                                            <span className="text-sm font-normal text-muted-foreground">
                                                (you)
                                            </span>
                                        )}
                                        {!u.joined && (
                                            <Chip tone="warning">Invited</Chip>
                                        )}
                                    </div>
                                    <div className="truncate text-sm text-muted-foreground">
                                        {u.email}
                                    </div>
                                </div>

                                {u.manageable ? (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <NativeSelect
                                            aria-label={`Role for ${u.name}`}
                                            className="h-8 w-auto"
                                            value={u.role}
                                            onChange={(e) =>
                                                router.patch(
                                                    UserAccountsController.update.url(
                                                        u.id,
                                                    ),
                                                    { role: e.target.value },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {roles.map((r) => (
                                                <option
                                                    key={r.value}
                                                    value={r.value}
                                                >
                                                    {r.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        {!u.joined && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        UserAccountsController.resend.url(
                                                            u.id,
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
                                                        `Remove ${u.name}? They won't be able to sign in.`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        UserAccountsController.destroy.url(
                                                            u.id,
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
                                ) : (
                                    <Chip tone="neutral">
                                        {roleLabels[u.role]}
                                    </Chip>
                                )}
                                {u.manageable && u.role === 'picker' && (
                                    <PickerAccess
                                        user={u}
                                        locations={locations}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>

                    <AddUser roles={roles} locations={locations} />
                </section>
            </div>
        </>
    );
}
