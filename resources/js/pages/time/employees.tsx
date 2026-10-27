import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import EmployeeController from '@/actions/App/Http/Controllers/Time/EmployeeController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';

type Employee = {
    id: number;
    name: string;
    job_title: string | null;
    user: { name: string; email: string; role: string } | null;
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
    users: { id: number; name: string; role: string }[];
    yards: { id: number; name: string }[];
    payTypes: Option[];
    photo: 'off' | 'everyone' | 'selected';
};

function EmployeeForm({
    employee,
    users,
    yards,
    payTypes,
    photo,
    onDone,
}: Omit<Props, 'employees'> & {
    employee: Employee | null;
    onDone: () => void;
}) {
    const form = useForm({
        user_id: '',
        name: employee?.name ?? '',
        job_title: employee?.job_title ?? '',
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
    const id = employee?.id ?? 'new';
    const linked = employee ? employee.user !== null : form.data.user_id !== '';

    return (
        <form
            className="grid gap-4 rounded-lg border border-border bg-card p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => {
                    const { user_id: userId, ...rest } = data;

                    return employee
                        ? rest
                        : { ...rest, user_id: userId === '' ? null : userId };
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
            <div className="grid gap-4 sm:grid-cols-2">
                {!employee && users.length > 0 && (
                    <Field
                        label="Signs in to Yardline as"
                        htmlFor={`user-${id}`}
                        error={form.errors.user_id}
                        hint="Leave as “Time clock only” for someone who just punches at the counter tablet."
                    >
                        <NativeSelect
                            id={`user-${id}`}
                            value={form.data.user_id}
                            onChange={(e) =>
                                form.setData('user_id', e.target.value)
                            }
                        >
                            <option value="">Time clock only</option>
                            {users.map((u) => (
                                <option key={u.id} value={String(u.id)}>
                                    {u.name} ({u.role})
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                )}
                {(employee || !linked) && (
                    <Field
                        label="Name"
                        htmlFor={`name-${id}`}
                        error={form.errors.name}
                    >
                        <Input
                            id={`name-${id}`}
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </Field>
                )}
                <Field
                    label="Job"
                    htmlFor={`job-${id}`}
                    error={form.errors.job_title}
                >
                    <Input
                        id={`job-${id}`}
                        value={form.data.job_title}
                        placeholder="e.g. Yard hand, Driver"
                        onChange={(e) =>
                            form.setData('job_title', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Home yard"
                    htmlFor={`yard-${id}`}
                    error={form.errors.location_id}
                >
                    <NativeSelect
                        id={`yard-${id}`}
                        value={form.data.location_id}
                        onChange={(e) =>
                            form.setData('location_id', e.target.value)
                        }
                    >
                        <option value="">Any</option>
                        {yards.map((y) => (
                            <option key={y.id} value={String(y.id)}>
                                {y.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Pay"
                    htmlFor={`pay-${id}`}
                    error={form.errors.pay_type}
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
                    error={form.errors.payroll_id}
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
                    error={form.errors.pin}
                    hint={
                        employee?.has_pin
                            ? 'Leave blank to keep their PIN.'
                            : '4 to 6 digits. They enter it at the counter tablet.'
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
                <Button type="submit" disabled={form.processing}>
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
 * Who clocks in (time settings, screen 6): sign-ins and time-clock-only
 * yard hands, their PIN, pay type and payroll number.
 */
export default function Employees(props: Props) {
    const { employees, users } = props;
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const active = employees.filter((e) => !e.archived);
    const archived = employees.filter((e) => e.archived);

    return (
        <PageBody className="max-w-4xl">
            <Head title="Employees" />
            <PageHeader
                eyebrow="Team"
                title="Employees"
                description="Everyone who clocks in. Time clock PINs, pay type and payroll numbers live here; pay rates stay in payroll."
                actions={
                    <>
                        {users.length > 0 && (
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
                                Add everyone who signs in ({users.length})
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
                                <EmployeeForm
                                    {...props}
                                    employee={e}
                                    onDone={() => setEditing(null)}
                                />
                            ) : (
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="font-semibold">
                                            {e.name}
                                            {e.job_title && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {e.job_title}
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {e.user
                                                ? `Signs in as ${e.user.role.toLowerCase()} · ${e.user.email}`
                                                : 'Time clock only'}
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
                                                            e.user
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
                                            Archive
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
