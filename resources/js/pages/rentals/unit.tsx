import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FleetController from '@/actions/App/Http/Controllers/Rentals/FleetController';
import MaintenanceController from '@/actions/App/Http/Controllers/Rentals/MaintenanceController';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Field, NativeSelect } from '@/components/yard/field';
import {
    Notice,
    PageBody,
    PageHeader,
    Section,
    Stat,
    StatGrid,
} from '@/components/yard/page-header';
import { RentalStatusChip } from '@/components/yard/rental-status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney, formatQuantity } from '@/lib/format';
import { UnitForm } from './unit-form';

type Props = {
    unit: {
        id: number;
        serial: string;
        status: string;
        hour_meter: string | null;
        notes: string | null;
        purchase_cost_cents: number | null;
        location_id: number;
        service_interval_hours: number | null;
        service_interval_days: number | null;
        name: string;
        product_id: number;
        location: string;
        purchased_on: string | null;
        hours_until_service: number | null;
        service_due_on: string | null;
        service_due: boolean;
    };
    rentals: {
        id: number;
        number: string;
        status: string;
        customer: string;
        starts_at: string;
        ends_at: string;
        charge_cents: number;
        hours: number | null;
    }[];
    maintenance: {
        id: number;
        kind: string;
        status: string;
        description: string;
        hour_meter: string | null;
        cost_cents: number;
        opened_at: string;
        completed_at: string | null;
        user: string | null;
    }[];
    totals: { revenue: string; maintenance: string; rentals: number };
    products: {
        id: number;
        name: string;
        service_interval_hours: number | null;
        service_interval_days: number | null;
    }[];
    locations: { id: number; name: string }[];
};

/**
 * One unit's history: every rental and every repair, so you can see what a
 * machine earns and costs.
 */
export default function Unit({
    unit,
    rentals,
    maintenance,
    totals,
    products,
    locations,
}: Props) {
    const [editing, setEditing] = useState(false);
    const service = useForm({
        rental_unit_id: unit.id,
        kind: 'service',
        description: '',
        done: true,
        hour_meter: unit.hour_meter ?? '',
        cost: '',
    });

    return (
        <>
            <Head title={`${unit.serial} · ${unit.name}`} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <Link
                            href={FleetController.index()}
                            className="hover:underline"
                        >
                            Fleet
                        </Link>
                    }
                    title={`${unit.name} · ${unit.serial}`}
                    description={`${unit.location} · ${unit.hour_meter ? `${formatQuantity(unit.hour_meter)} hrs` : 'no hour meter'}`}
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setEditing(!editing)}
                            >
                                {editing ? 'Close' : 'Edit unit'}
                            </Button>
                            <Button asChild>
                                <Link
                                    href={RentalController.create({
                                        query: {
                                            rental_unit_id: unit.id,
                                            product_id: unit.product_id,
                                        },
                                    })}
                                >
                                    New rental
                                </Link>
                            </Button>
                        </>
                    }
                />
                {unit.service_due && (
                    <Notice tone="warning">
                        Service is due. {unit.serial} is locked out of new
                        rentals until the service is logged below.
                    </Notice>
                )}
                {editing && (
                    <UnitForm
                        unit={unit}
                        products={products}
                        locations={locations}
                    />
                )}

                <StatGrid>
                    <Stat label="Rentals" value={totals.rentals} />
                    <Stat label="Rental revenue" value={totals.revenue} />
                    <Stat label="Maintenance cost" value={totals.maintenance} />
                    <Stat
                        label="Next service"
                        value={
                            unit.hours_until_service !== null
                                ? `${Math.max(0, unit.hours_until_service)} hrs`
                                : unit.service_due_on
                                  ? formatDate(unit.service_due_on)
                                  : '—'
                        }
                        tone={unit.service_due ? 'warning' : undefined}
                    />
                </StatGrid>

                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        title="Rental history"
                        flush
                        className="min-w-0 flex-[999_1_560px]"
                    >
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Rental</Th>
                                    <Th>Customer</Th>
                                    <Th>Dates</Th>
                                    <Th numeric>Hours used</Th>
                                    <Th numeric>Charged</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {rentals.length === 0 && (
                                    <EmptyRow colSpan={5}>
                                        Not rented yet.
                                    </EmptyRow>
                                )}
                                {rentals.map((r) => (
                                    <tr key={r.id}>
                                        <Td>
                                            <Link
                                                href={RentalController.show(
                                                    r.id,
                                                )}
                                                className="figure font-medium hover:underline"
                                            >
                                                {r.number}
                                            </Link>
                                            <div className="mt-1">
                                                <RentalStatusChip
                                                    status={r.status}
                                                />
                                            </div>
                                        </Td>
                                        <Td>{r.customer}</Td>
                                        <Td className="whitespace-nowrap text-subtle">
                                            {formatDate(r.starts_at)} –{' '}
                                            {formatDate(r.ends_at)}
                                        </Td>
                                        <Td numeric>{r.hours ?? '—'}</Td>
                                        <Td numeric>
                                            {formatMoney(r.charge_cents)}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Section>

                    <div className="flex min-w-0 flex-[1_1_320px] flex-col gap-5">
                        <Section title="Log service">
                            <form
                                className="grid gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    service.post(
                                        MaintenanceController.store.url(),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                service.reset(
                                                    'description',
                                                    'cost',
                                                ),
                                        },
                                    );
                                }}
                            >
                                <Field
                                    label="What was done"
                                    htmlFor="svc_desc"
                                    error={service.errors.description}
                                >
                                    <Input
                                        id="svc_desc"
                                        value={service.data.description}
                                        onChange={(e) =>
                                            service.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="250-hr service, oil and filters"
                                        required
                                    />
                                </Field>
                                <div className="grid grid-cols-2 gap-3">
                                    <Field
                                        label="Hour meter"
                                        htmlFor="svc_hours"
                                    >
                                        <Input
                                            id="svc_hours"
                                            inputMode="decimal"
                                            value={service.data.hour_meter}
                                            onChange={(e) =>
                                                service.setData(
                                                    'hour_meter',
                                                    e.target.value,
                                                )
                                            }
                                            className="figure"
                                        />
                                    </Field>
                                    <Field label="Cost" htmlFor="svc_cost">
                                        <Input
                                            id="svc_cost"
                                            inputMode="decimal"
                                            value={service.data.cost}
                                            onChange={(e) =>
                                                service.setData(
                                                    'cost',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <Field label="Type" htmlFor="svc_kind">
                                    <NativeSelect
                                        id="svc_kind"
                                        value={`${service.data.kind}:${service.data.done ? 'done' : 'open'}`}
                                        onChange={(e) => {
                                            const [kind, state] =
                                                e.target.value.split(':');
                                            service.setData((d) => ({
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
                                <Button
                                    type="submit"
                                    disabled={service.processing}
                                >
                                    Save
                                </Button>
                            </form>
                        </Section>

                        <Section title="Maintenance log">
                            {maintenance.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing logged yet.
                                </p>
                            ) : (
                                <ul className="grid gap-3 text-sm">
                                    {maintenance.map((m) => (
                                        <li key={m.id} className="grid gap-0.5">
                                            <div className="flex justify-between gap-3">
                                                <span className="font-medium">
                                                    {m.description}
                                                </span>
                                                <span className="figure">
                                                    {m.cost_cents
                                                        ? formatMoney(
                                                              m.cost_cents,
                                                          )
                                                        : ''}
                                                </span>
                                            </div>
                                            <div className="text-muted-foreground">
                                                {m.status === 'open'
                                                    ? 'In the shop since'
                                                    : m.kind === 'service'
                                                      ? 'Serviced'
                                                      : 'Repaired'}{' '}
                                                {formatDate(
                                                    m.completed_at ??
                                                        m.opened_at,
                                                )}
                                                {m.hour_meter
                                                    ? ` at ${formatQuantity(m.hour_meter)} hrs`
                                                    : ''}
                                                {m.user ? ` · ${m.user}` : ''}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </div>
                </div>
            </PageBody>
        </>
    );
}

Unit.layout = {
    breadcrumbs: [{ title: 'Fleet', href: FleetController.index() }],
};
