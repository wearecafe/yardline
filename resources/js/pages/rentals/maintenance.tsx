import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FleetController from '@/actions/App/Http/Controllers/Rentals/FleetController';
import MaintenanceController from '@/actions/App/Http/Controllers/Rentals/MaintenanceController';
import { Chip } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney, formatQuantity } from '@/lib/format';

type Record = {
    id: number;
    kind: string;
    status: string;
    description: string;
    hour_meter: string | null;
    cost_cents: number;
    unit_id: number;
    serial: string;
    name: string;
    opened_at: string;
    completed_at: string | null;
    user: string | null;
};

type Props = {
    due: {
        id: number;
        name: string;
        serial: string;
        hour_meter: string | null;
        hours_until: number | null;
        due_on: string | null;
        overdue: boolean;
    }[];
    open: Record[];
    recent: Record[];
    units: { id: number; label: string; hour_meter: string | null }[];
};

function UnitLink({
    id,
    serial,
    name,
}: {
    id: number;
    serial: string;
    name: string;
}) {
    return (
        <Link href={FleetController.show(id)} className="hover:underline">
            <span className="font-medium">{name}</span>{' '}
            <span className="figure text-muted-foreground">{serial}</span>
        </Link>
    );
}

function CompleteRow({ record }: { record: Record }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        cost: '',
        hour_meter: record.hour_meter ?? '',
        notes: '',
    });

    return (
        <tr>
            <Td>
                <UnitLink
                    id={record.unit_id}
                    serial={record.serial}
                    name={record.name}
                />
            </Td>
            <Td>
                {record.description}
                <div className="text-xs text-muted-foreground">
                    {record.kind === 'repair' ? 'Repair' : 'Service'} · in since{' '}
                    {formatDate(record.opened_at)}
                    {record.user ? ` · ${record.user}` : ''}
                </div>
            </Td>
            <Td className="text-right">
                {open ? (
                    <form
                        className="flex flex-wrap items-center justify-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                MaintenanceController.complete.url(record.id),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Input
                            aria-label="Cost"
                            placeholder="Cost"
                            inputMode="decimal"
                            className="w-28"
                            value={form.data.cost}
                            onChange={(e) =>
                                form.setData('cost', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Hour meter"
                            placeholder="Hours"
                            inputMode="decimal"
                            className="figure w-24"
                            value={form.data.hour_meter}
                            onChange={(e) =>
                                form.setData('hour_meter', e.target.value)
                            }
                        />
                        <Button type="submit" disabled={form.processing}>
                            Back in fleet
                        </Button>
                    </form>
                ) : (
                    <Button variant="outline" onClick={() => setOpen(true)}>
                        Mark done
                    </Button>
                )}
            </Td>
        </tr>
    );
}

/**
 * The shop: what's due for service, what's in for repair, and the log.
 * A unit past its interval can't be rented until its service is logged.
 */
export default function MaintenancePage({ due, open, recent, units }: Props) {
    const form = useForm({
        rental_unit_id: String(units[0]?.id ?? ''),
        kind: 'service',
        done: true,
        description: '',
        hour_meter: units[0]?.hour_meter ?? '',
        cost: '',
    });

    return (
        <>
            <Head title="Maintenance" />
            <PageBody>
                <PageHeader
                    title="Maintenance"
                    description="Service intervals by hour meter or date. Units past due are locked out of new rentals."
                />

                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[999_1_560px] flex-col gap-5">
                        <Section title="Due for service" flush>
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>Unit</Th>
                                        <Th numeric>Hour meter</Th>
                                        <Th>Due</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {due.length === 0 && (
                                        <EmptyRow colSpan={3}>
                                            Nothing due in the next 25 hours or
                                            two weeks.
                                        </EmptyRow>
                                    )}
                                    {due.map((unit) => (
                                        <tr key={unit.id}>
                                            <Td>
                                                <UnitLink
                                                    id={unit.id}
                                                    serial={unit.serial}
                                                    name={unit.name}
                                                />
                                            </Td>
                                            <Td numeric>
                                                {unit.hour_meter
                                                    ? formatQuantity(
                                                          unit.hour_meter,
                                                      )
                                                    : '—'}
                                            </Td>
                                            <Td>
                                                {unit.overdue ? (
                                                    <Chip tone="danger">
                                                        Overdue · locked out
                                                    </Chip>
                                                ) : unit.hours_until !==
                                                  null ? (
                                                    <Chip tone="warning">
                                                        In {unit.hours_until}{' '}
                                                        hrs
                                                    </Chip>
                                                ) : unit.due_on ? (
                                                    <Chip tone="warning">
                                                        {formatDate(
                                                            unit.due_on,
                                                        )}
                                                    </Chip>
                                                ) : null}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Section>

                        <Section title="In the shop" flush>
                            <Table>
                                <tbody>
                                    {open.length === 0 && (
                                        <EmptyRow colSpan={3}>
                                            Nothing in the shop.
                                        </EmptyRow>
                                    )}
                                    {open.map((record) => (
                                        <CompleteRow
                                            key={record.id}
                                            record={record}
                                        />
                                    ))}
                                </tbody>
                            </Table>
                        </Section>

                        <Section title="Recent work" flush>
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>Unit</Th>
                                        <Th>Work</Th>
                                        <Th>Done</Th>
                                        <Th numeric>Cost</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent.length === 0 && (
                                        <EmptyRow colSpan={4}>
                                            No work logged yet.
                                        </EmptyRow>
                                    )}
                                    {recent.map((record) => (
                                        <tr key={record.id}>
                                            <Td>
                                                <UnitLink
                                                    id={record.unit_id}
                                                    serial={record.serial}
                                                    name={record.name}
                                                />
                                            </Td>
                                            <Td>
                                                {record.description}
                                                {record.hour_meter && (
                                                    <div className="figure text-xs text-muted-foreground">
                                                        at{' '}
                                                        {formatQuantity(
                                                            record.hour_meter,
                                                        )}{' '}
                                                        hrs
                                                    </div>
                                                )}
                                            </Td>
                                            <Td className="whitespace-nowrap text-subtle">
                                                {formatDate(
                                                    record.completed_at ??
                                                        record.opened_at,
                                                )}
                                                {record.user && (
                                                    <div className="text-xs">
                                                        {record.user}
                                                    </div>
                                                )}
                                            </Td>
                                            <Td numeric>
                                                {record.cost_cents
                                                    ? formatMoney(
                                                          record.cost_cents,
                                                      )
                                                    : '—'}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Section>
                    </div>

                    <Section
                        title="Log work"
                        className="min-w-0 flex-[1_1_320px]"
                    >
                        <form
                            className="grid gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(MaintenanceController.store.url(), {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset('description', 'cost'),
                                });
                            }}
                        >
                            <Field
                                label="Unit"
                                htmlFor="m_unit"
                                error={form.errors.rental_unit_id}
                            >
                                <NativeSelect
                                    id="m_unit"
                                    value={form.data.rental_unit_id}
                                    onChange={(e) => {
                                        const unit = units.find(
                                            (u) =>
                                                String(u.id) === e.target.value,
                                        );
                                        form.setData((d) => ({
                                            ...d,
                                            rental_unit_id: e.target.value,
                                            hour_meter: unit?.hour_meter ?? '',
                                        }));
                                    }}
                                >
                                    {units.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Type"
                                htmlFor="m_kind"
                                error={form.errors.kind}
                            >
                                <NativeSelect
                                    id="m_kind"
                                    value={`${form.data.kind}:${form.data.done ? 'done' : 'open'}`}
                                    onChange={(e) => {
                                        const [kind, state] =
                                            e.target.value.split(':');
                                        form.setData((d) => ({
                                            ...d,
                                            kind,
                                            done: state === 'done',
                                        }));
                                    }}
                                >
                                    <option value="service:done">
                                        Service done (resets the counter)
                                    </option>
                                    <option value="repair:open">
                                        Send to the shop for repair
                                    </option>
                                    <option value="service:open">
                                        Send to the shop for service
                                    </option>
                                </NativeSelect>
                            </Field>
                            <Field
                                label="What was done"
                                htmlFor="m_desc"
                                error={form.errors.description}
                            >
                                <Input
                                    id="m_desc"
                                    required
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="250-hr service, oil and filters"
                                />
                            </Field>
                            {form.data.done && (
                                <div className="grid grid-cols-2 gap-3">
                                    <Field
                                        label="Hour meter"
                                        htmlFor="m_hours"
                                        error={form.errors.hour_meter}
                                    >
                                        <Input
                                            id="m_hours"
                                            inputMode="decimal"
                                            className="figure"
                                            value={form.data.hour_meter}
                                            onChange={(e) =>
                                                form.setData(
                                                    'hour_meter',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Cost"
                                        htmlFor="m_cost"
                                        error={form.errors.cost}
                                    >
                                        <Input
                                            id="m_cost"
                                            inputMode="decimal"
                                            value={form.data.cost}
                                            onChange={(e) =>
                                                form.setData(
                                                    'cost',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            )}
                            <Button
                                type="submit"
                                disabled={form.processing || units.length === 0}
                            >
                                Save
                            </Button>
                        </form>
                    </Section>
                </div>
            </PageBody>
        </>
    );
}

MaintenancePage.layout = {
    breadcrumbs: [
        { title: 'Maintenance', href: MaintenanceController.index() },
    ],
};
