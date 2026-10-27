import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import RoleSettingsController from '@/actions/App/Http/Controllers/Settings/RoleSettingsController';
import UserAccountsController from '@/actions/App/Http/Controllers/Settings/UserAccountsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import {
    RolePicker,
    scoped,
    timeOnly,
    YardPicker,
} from '@/components/yard/people-pickers';
import type { RoleOption, Yard } from '@/components/yard/people-pickers';

type PermissionOption = {
    value: string;
    label: string;
    description: string;
    grantable: boolean;
    roles: string[];
};

type UserAccount = {
    id: number;
    name: string;
    email: string | null;
    role: string;
    roles: string[];
    joined: boolean;
    signs_in: boolean;
    is_you: boolean;
    manageable: boolean;
    yard_scoped: boolean;
    yards: number[];
    permissions: Record<string, boolean>;
    effective: string[];
};

type Props = {
    users: UserAccount[];
    roles: RoleOption[];
    permissions: PermissionOption[];
    locations: Yard[];
    reports: { admins_can_view_profit: boolean };
    isOwner: boolean;
};

/** A permission on one person: from their roles, granted or removed. */
type Override = boolean | null;

const ownerRole = { value: 'owner', label: 'Owner' };

function roleLabel(roles: RoleOption[], value: string): string {
    return [...roles, ownerRole].find((r) => r.value === value)?.label ?? value;
}

/** Each permission: what their roles give, or granted or removed on them. */
function PermissionPicker({
    id,
    permissions,
    roles,
    value,
    onChange,
}: {
    id: string;
    permissions: PermissionOption[];
    roles: string[];
    value: Record<string, Override>;
    onChange: (value: Record<string, Override>) => void;
}) {
    return (
        <ul className="grid gap-2">
            {permissions.map((p) => {
                const fromRoles =
                    roles.includes('owner') ||
                    p.roles.some((r) => roles.includes(r));
                const current = value[p.value] ?? null;
                const selectId = `${id}-${p.value}`;

                return (
                    <li
                        key={p.value}
                        className="flex flex-wrap items-center justify-between gap-2"
                    >
                        <label htmlFor={selectId} className="min-w-0 text-sm">
                            <span className="font-medium">{p.label}</span>
                            <span className="block text-xs text-muted-foreground">
                                {p.description}
                            </span>
                        </label>
                        <NativeSelect
                            id={selectId}
                            className="h-8 w-auto"
                            disabled={!p.grantable}
                            value={
                                current === null
                                    ? 'roles'
                                    : current
                                      ? 'grant'
                                      : 'remove'
                            }
                            onChange={(e) =>
                                onChange({
                                    ...value,
                                    [p.value]:
                                        e.target.value === 'roles'
                                            ? null
                                            : e.target.value === 'grant',
                                })
                            }
                        >
                            <option value="roles">
                                {fromRoles
                                    ? 'Yes, from their roles'
                                    : 'No, from their roles'}
                            </option>
                            <option value="grant">Granted</option>
                            <option value="remove">Removed</option>
                        </NativeSelect>
                    </li>
                );
            })}
        </ul>
    );
}

function EditUser({
    user,
    roles,
    permissions,
    locations,
    onDone,
}: {
    user: UserAccount;
    roles: RoleOption[];
    permissions: PermissionOption[];
    locations: Yard[];
    onDone: () => void;
}) {
    const form = useForm({
        email: '',
        roles: user.roles,
        location_ids: user.yards,
        permissions: user.permissions as Record<string, Override>,
    });
    const errors = form.errors as Record<string, string | undefined>;

    // Send every permission, so ones set back to "from their roles" clear.
    const payload = () => ({
        ...form.data,
        email:
            user.signs_in || form.data.email === ''
                ? undefined
                : form.data.email,
        permissions: Object.fromEntries(
            permissions.map((p) => [
                p.value,
                form.data.permissions[p.value] ?? null,
            ]),
        ),
    });

    return (
        <form
            className="grid w-full gap-5 rounded-md bg-secondary/50 p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform(payload);
                form.patch(UserAccountsController.update.url(user.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <Field
                label="Roles"
                htmlFor={`edit-roles-${user.id}`}
                error={errors.roles}
            >
                <RolePicker
                    id={`edit-roles-${user.id}`}
                    roles={roles}
                    value={form.data.roles}
                    onChange={(values) => form.setData('roles', values)}
                />
            </Field>

            {!user.signs_in && (
                <Field
                    label="Email"
                    htmlFor={`edit-email-${user.id}`}
                    error={form.errors.email}
                    hint={
                        timeOnly(form.data.roles)
                            ? 'Optional. With one, they can sign in to see their own hours.'
                            : 'They need one to sign in. We’ll email them a link to choose a password.'
                    }
                >
                    <Input
                        id={`edit-email-${user.id}`}
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </Field>
            )}

            {scoped(roles, form.data.roles) && (
                <Field
                    label="Yards they work"
                    htmlFor={`edit-yards-${user.id}`}
                    hint="They only see orders, stock, trucks and units at these yards."
                    error={errors.location_ids}
                >
                    <YardPicker
                        id={`edit-yards-${user.id}`}
                        locations={locations}
                        value={form.data.location_ids}
                        onChange={(ids) => form.setData('location_ids', ids)}
                    />
                </Field>
            )}

            {!timeOnly(form.data.roles) && (
                <Field
                    label="Permissions"
                    htmlFor={`edit-perms-${user.id}`}
                    hint="Each one follows their roles unless you grant or remove it for this person."
                >
                    <PermissionPicker
                        id={`edit-perms-${user.id}`}
                        permissions={permissions}
                        roles={form.data.roles}
                        value={form.data.permissions}
                        onChange={(value) => form.setData('permissions', value)}
                    />
                </Field>
            )}

            <div className="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing || form.data.roles.length === 0}
                >
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

function AddUser({ roles, locations }: Pick<Props, 'roles' | 'locations'>) {
    const form = useForm({
        name: '',
        email: '',
        roles: ['sales'] as string[],
        location_ids: locations.map((l) => l.id),
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            className="grid gap-4 rounded-lg border border-border p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(UserAccountsController.store.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    label="Name"
                    htmlFor="user-name"
                    error={form.errors.name}
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
                    hint={
                        timeOnly(form.data.roles)
                            ? 'Optional. Without one they clock in at the counter tablet with a PIN and never sign in.'
                            : undefined
                    }
                >
                    <Input
                        id="user-email"
                        type="email"
                        required={!timeOnly(form.data.roles)}
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </Field>
            </div>
            <Field label="Roles" htmlFor="user-roles" error={errors.roles}>
                <RolePicker
                    id="user-roles"
                    roles={roles}
                    value={form.data.roles}
                    onChange={(values) => form.setData('roles', values)}
                />
            </Field>
            {scoped(roles, form.data.roles) && (
                <Field
                    label="Yards they work"
                    htmlFor="user-yards"
                    error={errors.location_ids}
                >
                    <YardPicker
                        id="user-yards"
                        locations={locations}
                        value={form.data.location_ids}
                        onChange={(ids) => form.setData('location_ids', ids)}
                    />
                </Field>
            )}
            <div>
                <Button
                    type="submit"
                    disabled={form.processing || form.data.roles.length === 0}
                >
                    Add user
                </Button>
            </div>
        </form>
    );
}

export default function UsersSettings({
    users,
    roles,
    permissions,
    locations,
    reports,
    isOwner,
}: Props) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title="Users" />
            <h1 className="sr-only">Users</h1>

            <div className="space-y-10">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Users"
                        description="People who sign in to the yard. Give each person the roles for the jobs they do; they get the screens of every role they hold. Everyone but owners and admins works only the yards you tick. People who only drive are added under Drivers."
                    />
                    <p className="text-sm text-muted-foreground">
                        What each role can do, and refund limits, are set under{' '}
                        <Link
                            href={RoleSettingsController.edit.url()}
                            className="underline underline-offset-2"
                        >
                            Roles
                        </Link>
                        .
                    </p>

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
                                        {u.signs_in && !u.joined && (
                                            <Chip tone="warning">Invited</Chip>
                                        )}
                                    </div>
                                    <div className="truncate text-sm text-muted-foreground">
                                        {u.email ??
                                            'No sign-in. Clocks in at the counter tablet.'}
                                    </div>
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {u.roles.map((r) => (
                                            <Chip key={r} tone="neutral">
                                                {roleLabel(roles, r)}
                                            </Chip>
                                        ))}
                                        {u.yard_scoped &&
                                            locations.length > 1 &&
                                            u.yards.length <
                                                locations.length && (
                                                <Chip tone="info">
                                                    {u.yards.length === 0
                                                        ? 'No yards'
                                                        : locations
                                                              .filter((l) =>
                                                                  u.yards.includes(
                                                                      l.id,
                                                                  ),
                                                              )
                                                              .map(
                                                                  (l) => l.name,
                                                              )
                                                              .join(', ')}
                                                </Chip>
                                            )}
                                    </div>
                                </div>

                                {u.manageable && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setEditing(
                                                    editing === u.id
                                                        ? null
                                                        : u.id,
                                                )
                                            }
                                        >
                                            {editing === u.id
                                                ? 'Close'
                                                : 'Roles and access'}
                                        </Button>
                                        {u.signs_in && !u.joined && (
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
                                )}
                                {u.manageable && editing === u.id && (
                                    <EditUser
                                        user={u}
                                        roles={roles}
                                        permissions={permissions}
                                        locations={locations}
                                        onDone={() => setEditing(null)}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>

                    <AddUser roles={roles} locations={locations} />
                </section>

                {isOwner && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Profit report"
                            description="Costs, margins and gross profit. You always see the profit report. Anyone else needs See gross-profit P&L, from their role or given to them."
                        />
                        <Checkbox
                            id="admins-view-profit"
                            label="Admins can see the profit report"
                            hint="Includes product costs and the margin on every account."
                            checked={reports.admins_can_view_profit}
                            onChange={(on) =>
                                router.put(
                                    UserAccountsController.reports.url(),
                                    { admins_can_view_profit: on },
                                    { preserveScroll: true },
                                )
                            }
                        />
                    </section>
                )}
            </div>
        </>
    );
}
