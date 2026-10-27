import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import EmployeeController from '@/actions/App/Http/Controllers/Time/EmployeeController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import {
    RolePicker,
    scoped,
    TIME_ONLY,
    timeOnly,
    YardPicker,
} from '@/components/yard/people-pickers';
import type { RoleOption, Yard } from '@/components/yard/people-pickers';

/** The user behind an employee. */
type Person = {
    id: number;
    name: string;
    email: string | null;
    roles: string[];
    time_only: boolean;
    /** Yards they work, or null for every yard (owner and admin). */
    yards: number[] | null;
    deactivated: boolean;
};

type Employee = {
    id: number;
    name: string;
    user: Person;
    location_id: number | null;
    yard: string | null;
    pay_type: 'hourly' | 'salaried';
    tracks_time: boolean;
    overtime_exempt: boolean;
    take_home_truck: boolean;
    photo_on_punch: boolean;
    payroll_id: string | null;
    has_pin: boolean;
    archived: boolean;
};

type Option = { value: string; label: string };

type Props = {
    employees: Employee[];
    /** Users who aren't employees yet. */
    users: Person[];
    /** Roles a new user added here can have. */
    roles: RoleOption[];
    yards: Yard[];
    payTypes: Option[];
    photo: 'off' | 'everyone' | 'selected';
};

const NEW = 'new';

function describe(person: Person): string {
    if (person.time_only) {
        return person.email
            ? `Time & attendance only · ${person.email}`
            : 'Time & attendance only · no sign-in';
    }

    return `${person.roles.join(', ')} · ${person.email ?? ''}`;
}

function EmployeeForm({
    employee,
    users,
    roles,
    yards,
    payTypes,
    photo,
    onDone,
}: Omit<Props, 'employees'> & {
    employee: Employee | null;
    onDone: () => void;
}) {
    const form = useForm({
        person: users.length === 0 ? NEW : '',
        new_name: '',
        new_email: '',
        new_roles: roles.some((r) => r.value === TIME_ONLY)
            ? [TIME_ONLY]
            : ([] as string[]),
        new_yards: yards.map((y) => y.id),
        location_id: employee?.location_id ? String(employee.location_id) : '',
        pay_type: employee?.pay_type ?? 'hourly',
        tracks_time: employee?.tracks_time ?? true,
        overtime_exempt: employee?.overtime_exempt ?? false,
        take_home_truck: employee?.take_home_truck ?? false,
        photo_on_punch: employee?.photo_on_punch ?? false,
        payroll_id: employee?.payroll_id ?? '',
        pin: '',
        clear_pin: false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const id = employee?.id ?? 'new';
    const adding = !employee && form.data.person === NEW;
    const picked = employee
        ? employee.user
        : users.find((u) => String(u.id) === form.data.person);

    // A home yard is one of the yards they work.
    const theirYards: number[] | null = adding
        ? scoped(roles, form.data.new_roles)
            ? form.data.new_yards
            : null
        : (picked?.yards ?? null);
    const homeYards =
        theirYards === null
            ? yards
            : yards.filter((y) => theirYards.includes(y.id));
    const homeYardFits =
        form.data.location_id === '' ||
        homeYards.some((y) => String(y.id) === form.data.location_id);

    return (
        <form
            className="grid gap-4 rounded-lg border border-border bg-card p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => {
                    const {
                        person,
                        new_name: name,
                        new_email: email,
                        new_roles: newRoles,
                        new_yards: newYards,
                        ...rest
                    } = data;
                    const fields = {
                        ...rest,
                        location_id: homeYardFits ? rest.location_id : '',
                    };

                    if (employee) {
                        return fields;
                    }

                    return person === NEW
                        ? {
                              ...fields,
                              new_user: {
                                  name,
                                  email: email === '' ? null : email,
                                  roles: newRoles,
                                  location_ids: newYards,
                              },
                          }
                        : {
                              ...fields,
                              user_id: person === '' ? null : Number(person),
                          };
                });

                const options = {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onDone();
                    },
                };

                if (employee) {
                    form.put(
                        EmployeeController.update.url(employee.id),
                        options,
                    );
                } else {
                    form.post(EmployeeController.store.url(), options);
                }
            }}
        >
            {!employee && (
                <Field
                    label="Person"
                    htmlFor={`person-${id}`}
                    error={errors.user_id}
                    hint="Everyone who clocks in is a user. Pick someone, or add them as a new user."
                >
                    <NativeSelect
                        id={`person-${id}`}
                        value={form.data.person}
                        onChange={(e) => form.setData('person', e.target.value)}
                    >
                        {users.length > 0 && (
                            <option value="" disabled>
                                Choose someone…
                            </option>
                        )}
                        {users.map((u) => (
                            <option key={u.id} value={String(u.id)}>
                                {u.name} (
                                {u.time_only
                                    ? 'time clock only'
                                    : u.roles.join(', ')}
                                )
                            </option>
                        ))}
                        <option value={NEW}>+ New user</option>
                    </NativeSelect>
                </Field>
            )}

            {picked && (
                <p className="text-sm text-muted-foreground">
                    {describe(picked)}. Their name, roles and yards are changed
                    under Settings → Users.
                </p>
            )}

            {adding && (
                <div className="grid gap-4 rounded-md bg-secondary/50 p-3">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Name"
                            htmlFor={`new-name-${id}`}
                            error={errors['new_user.name']}
                        >
                            <Input
                                id={`new-name-${id}`}
                                required
                                value={form.data.new_name}
                                onChange={(e) =>
                                    form.setData('new_name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Email"
                            htmlFor={`new-email-${id}`}
                            error={errors['new_user.email']}
                            hint={
                                timeOnly(form.data.new_roles)
                                    ? 'Optional. Without one they never sign in and punch at the counter tablet with their PIN.'
                                    : 'We’ll email them a link to choose a password.'
                            }
                        >
                            <Input
                                id={`new-email-${id}`}
                                type="email"
                                required={!timeOnly(form.data.new_roles)}
                                value={form.data.new_email}
                                onChange={(e) =>
                                    form.setData('new_email', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        label="Roles"
                        htmlFor={`new-roles-${id}`}
                        error={
                            errors['new_user.roles'] ??
                            errors['new_user.roles.0']
                        }
                    >
                        <RolePicker
                            id={`new-roles-${id}`}
                            roles={roles}
                            value={form.data.new_roles}
                            onChange={(values) =>
                                form.setData('new_roles', values)
                            }
                        />
                    </Field>
                    {scoped(roles, form.data.new_roles) && yards.length > 1 && (
                        <Field
                            label="Yards they work"
                            htmlFor={`new-yards-${id}`}
                            error={errors['new_user.location_ids']}
                        >
                            <YardPicker
                                id={`new-yards-${id}`}
                                locations={yards}
                                value={form.data.new_yards}
                                onChange={(ids) =>
                                    form.setData('new_yards', ids)
                                }
                            />
                        </Field>
                    )}
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Home yard"
                    htmlFor={`yard-${id}`}
                    error={errors.location_id}
                    hint="One of the yards they work."
                >
                    <NativeSelect
                        id={`yard-${id}`}
                        value={homeYardFits ? form.data.location_id : ''}
                        onChange={(e) =>
                            form.setData('location_id', e.target.value)
                        }
                    >
                        <option value="">Any</option>
                        {homeYards.map((y) => (
                            <option key={y.id} value={String(y.id)}>
                                {y.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Pay"
                    htmlFor={`pay-${id}`}
                    error={errors.pay_type}
                >
                    <NativeSelect
                        id={`pay-${id}`}
                        value={form.data.pay_type}
                        onChange={(e) =>
                            form.setData(
                                'pay_type',
                                e.target.value as Employee['pay_type'],
                            )
                        }
                    >
                        {payTypes.map((p) => (
                            <option key={p.value} value={p.value}>
                                {p.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Payroll ID"
                    htmlFor={`payroll-${id}`}
                    error={errors.payroll_id}
                    hint="Their number in payroll (ADP file number)."
                >
                    <Input
                        id={`payroll-${id}`}
                        value={form.data.payroll_id}
                        onChange={(e) =>
                            form.setData('payroll_id', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label={
                        employee?.has_pin ? 'New PIN' : 'PIN for the time clock'
                    }
                    htmlFor={`pin-${id}`}
                    error={errors.pin}
                    hint={
                        employee?.has_pin
                            ? 'Leave blank to keep their PIN.'
                            : '4 to 6 digits. They enter it at the counter tablet, and to switch in on a shared yard tablet or truck device.'
                    }
                >
                    <Input
                        id={`pin-${id}`}
                        inputMode="numeric"
                        autoComplete="off"
                        value={form.data.pin}
                        onChange={(e) =>
                            form.setData(
                                'pin',
                                e.target.value.replace(/\D/g, '').slice(0, 6),
                            )
                        }
                    />
                </Field>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <Checkbox
                    id={`tracks-${id}`}
                    label="Clocks in"
                    hint="Off for staff who only take time off."
                    checked={form.data.tracks_time}
                    onChange={(v) => form.setData('tracks_time', v)}
                />
                <Checkbox
                    id={`exempt-${id}`}
                    label="No overtime (manager or exempt role)"
                    checked={form.data.overtime_exempt}
                    onChange={(v) => form.setData('overtime_exempt', v)}
                />
                <Checkbox
                    id={`truck-${id}`}
                    label="Takes a truck home"
                    hint="Can start and end the day away from the yard without a flag."
                    checked={form.data.take_home_truck}
                    onChange={(v) => form.setData('take_home_truck', v)}
                />
                {photo === 'selected' && (
                    <Checkbox
                        id={`photo-${id}`}
                        label="Photo on each time clock punch"
                        checked={form.data.photo_on_punch}
                        onChange={(v) => form.setData('photo_on_punch', v)}
                    />
                )}
                {employee?.has_pin && (
                    <Checkbox
                        id={`clear-pin-${id}`}
                        label="Remove their PIN"
                        checked={form.data.clear_pin}
                        onChange={(v) => form.setData('clear_pin', v)}
                    />
                )}
            </div>
            <div className="flex gap-2">
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        (!employee && form.data.person === '') ||
                        (adding && form.data.new_roles.length === 0)
                    }
                >
                    {employee ? 'Save' : 'Add'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * Who clocks in (time settings, screen 6). Every employee is a user:
 * someone who signs in, or a yard hand with the Time & attendance only
 * role who just punches at the counter tablet. Their PIN, pay type, home
 * yard and payroll number live here.
 */
export default function Employees(props: Props) {
    const { employees, users } = props;
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const active = employees.filter((e) => !e.archived);
    const archived = employees.filter((e) => e.archived);
    const signsIn = users.filter((u) => u.email !== null);

    return (
        <PageBody className="max-w-4xl">
            <Head title="Employees" />
            <PageHeader
                eyebrow="Team"
                title="Employees"
                description="Everyone who clocks in, each one a user. Time clock PINs, pay type and payroll numbers live here; pay rates stay in payroll."
                actions={
                    <>
                        {signsIn.length > 0 && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        EmployeeController.addEveryone.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Add everyone who signs in ({signsIn.length})
                            </Button>
                        )}
                        <Button onClick={() => setEditing('new')}>
                            Add someone
                        </Button>
                    </>
                }
            />

            {editing === 'new' && (
                <EmployeeForm
                    {...props}
                    employee={null}
                    onDone={() => setEditing(null)}
                />
            )}

            <Section flush>
                <ul className="divide-y divide-secondary">
                    {active.length === 0 && (
                        <li className="px-5 py-10 text-center text-muted-foreground">
                            Nobody clocks in yet.
                        </li>
                    )}
                    {active.map((e) => (
                        <li key={e.id} className="px-5 py-4">
                            {editing === e.id ? (
                                <div className="grid gap-3">
                                    <div className="font-semibold">
                                        {e.name}
                                    </div>
                                    <EmployeeForm
                                        {...props}
                                        employee={e}
                                        onDone={() => setEditing(null)}
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="font-semibold">
                                            {e.name}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {describe(e.user)}
                                            {e.yard ? ` · ${e.yard}` : ''}
                                            {e.payroll_id
                                                ? ` · Payroll ${e.payroll_id}`
                                                : ''}
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            {!e.tracks_time && (
                                                <Chip>Doesn’t clock in</Chip>
                                            )}
                                            <Chip>
                                                {e.pay_type === 'hourly'
                                                    ? 'Hourly'
                                                    : 'Salaried'}
                                            </Chip>
                                            {e.overtime_exempt && (
                                                <Chip>No overtime</Chip>
                                            )}
                                            {e.take_home_truck && (
                                                <Chip>Take-home truck</Chip>
                                            )}
                                            {e.tracks_time &&
                                                (e.has_pin ? (
                                                    <Chip tone="success">
                                                        PIN set
                                                    </Chip>
                                                ) : (
                                                    <Chip
                                                        tone={
                                                            e.user.email
                                                                ? 'neutral'
                                                                : 'warning'
                                                        }
                                                    >
                                                        No PIN
                                                    </Chip>
                                                ))}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(e.id)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    EmployeeController.archive.url(
                                                        e.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            End employment
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </Section>

            {archived.length > 0 && (
                <Section title="No longer here" flush>
                    <ul className="divide-y divide-secondary">
                        {archived.map((e) => (
                            <li
                                key={e.id}
                                className="flex items-center justify-between gap-3 px-5 py-3"
                            >
                                <span className="text-muted-foreground">
                                    {e.name}
                                    {e.user.deactivated &&
                                        ' · user deactivated'}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(
                                            EmployeeController.restore.url(
                                                e.id,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Bring back
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </PageBody>
    );
}
