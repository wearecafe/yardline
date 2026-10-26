import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FleetController from '@/actions/App/Http/Controllers/Rentals/FleetController';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type UnitFormUnit = {
    id: number;
    product_id: number;
    location_id?: number;
    serial: string;
    status: string;
    hour_meter: string | null;
    notes: string | null;
    purchase_cost_cents: number | null;
    purchased_on: string | null;
    service_interval_hours?: number | null;
    service_interval_days?: number | null;
};

type Options = {
    products: {
        id: number;
        name: string;
        service_interval_hours: number | null;
        service_interval_days: number | null;
    }[];
    locations: { id: number; name: string }[];
};

export function UnitForm({
    unit,
    products,
    locations,
    productId,
}: Options & { unit: UnitFormUnit | null; productId?: number | null }) {
    const form = useForm({
        product_id: String(
            unit?.product_id ?? productId ?? products[0]?.id ?? '',
        ),
        location_id: String(unit?.location_id ?? locations[0]?.id ?? ''),
        serial: unit?.serial ?? '',
        status: unit?.status ?? 'available',
        hour_meter: unit?.hour_meter ?? '',
        service_interval_hours: unit?.service_interval_hours
            ? String(unit.service_interval_hours)
            : '',
        service_interval_days: unit?.service_interval_days
            ? String(unit.service_interval_days)
            : '',
        last_service_hours: '',
        last_service_on: '',
        purchased_on: unit?.purchased_on ?? '',
        purchase_cost: unit?.purchase_cost_cents
            ? (unit.purchase_cost_cents / 100).toFixed(2)
            : '',
        notes: unit?.notes ?? '',
    });
    const product = products.find((p) => String(p.id) === form.data.product_id);

    function submit(e: FormEvent) {
        e.preventDefault();

        if (unit) {
            form.put(FleetController.update.url(unit.id), {
                preserveScroll: true,
            });
        } else {
            form.post(FleetController.store.url());
        }
    }

    const text = (key: keyof typeof form.data) => ({
        id: key,
        value: form.data[key],
        onChange: (e: { target: { value: string } }) =>
            form.setData(key, e.target.value),
    });

    return (
        <form onSubmit={submit} className="grid max-w-3xl gap-5">
            <Section title="Unit">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Equipment"
                        htmlFor="product_id"
                        error={form.errors.product_id}
                        hint="The model and its rates. Add rental equipment under Products."
                    >
                        <NativeSelect
                            id="product_id"
                            value={form.data.product_id}
                            onChange={(e) =>
                                form.setData('product_id', e.target.value)
                            }
                        >
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        label="Serial or fleet number"
                        htmlFor="serial"
                        error={form.errors.serial}
                    >
                        <Input
                            {...text('serial')}
                            required
                            placeholder="PC-04"
                            className="figure"
                        />
                    </Field>
                    <Field
                        label="Yard"
                        htmlFor="location_id"
                        error={form.errors.location_id}
                    >
                        <NativeSelect
                            id="location_id"
                            value={form.data.location_id}
                            onChange={(e) =>
                                form.setData('location_id', e.target.value)
                            }
                        >
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        label="Status"
                        htmlFor="status"
                        error={form.errors.status}
                    >
                        <NativeSelect
                            id="status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            <option value="available">Available</option>
                            <option value="in_shop">In the shop</option>
                            <option value="retired">Retired</option>
                        </NativeSelect>
                    </Field>
                </div>
            </Section>
            <Section title="Hours and service">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        label="Hour meter"
                        htmlFor="hour_meter"
                        error={form.errors.hour_meter}
                    >
                        <Input
                            {...text('hour_meter')}
                            inputMode="decimal"
                            className="figure"
                        />
                    </Field>
                    <Field
                        label="Service every (hours)"
                        htmlFor="service_interval_hours"
                        error={form.errors.service_interval_hours}
                        hint={
                            product?.service_interval_hours
                                ? `Model default ${product.service_interval_hours}`
                                : undefined
                        }
                    >
                        <Input
                            {...text('service_interval_hours')}
                            inputMode="numeric"
                        />
                    </Field>
                    <Field
                        label="or every (days)"
                        htmlFor="service_interval_days"
                        error={form.errors.service_interval_days}
                        hint={
                            product?.service_interval_days
                                ? `Model default ${product.service_interval_days}`
                                : undefined
                        }
                    >
                        <Input
                            {...text('service_interval_days')}
                            inputMode="numeric"
                        />
                    </Field>
                    {!unit && (
                        <>
                            <Field
                                label="Last serviced at (hours)"
                                htmlFor="last_service_hours"
                                error={form.errors.last_service_hours}
                            >
                                <Input
                                    {...text('last_service_hours')}
                                    inputMode="decimal"
                                    className="figure"
                                />
                            </Field>
                            <Field
                                label="Last serviced on"
                                htmlFor="last_service_on"
                                error={form.errors.last_service_on}
                            >
                                <Input
                                    {...text('last_service_on')}
                                    type="date"
                                />
                            </Field>
                        </>
                    )}
                </div>
            </Section>
            <Section title="Purchase">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Bought on"
                        htmlFor="purchased_on"
                        error={form.errors.purchased_on}
                    >
                        <Input {...text('purchased_on')} type="date" />
                    </Field>
                    <Field
                        label="Cost"
                        htmlFor="purchase_cost"
                        error={form.errors.purchase_cost}
                        hint="Used for revenue against cost on the unit page."
                    >
                        <Input {...text('purchase_cost')} inputMode="decimal" />
                    </Field>
                </div>
                <Field label="Notes" htmlFor="notes" error={form.errors.notes}>
                    <Input {...text('notes')} />
                </Field>
            </Section>
            <div>
                <Button type="submit" disabled={form.processing}>
                    {unit ? 'Save unit' : 'Add to fleet'}
                </Button>
            </div>
        </form>
    );
}

export default function CreateUnit(
    props: Options & { unit: null; productId: number | null },
) {
    return (
        <>
            <Head title="Add unit" />
            <PageBody>
                <PageHeader
                    title="Add unit"
                    description="One machine in the rental fleet, tracked by its serial number."
                />
                <UnitForm {...props} />
            </PageBody>
        </>
    );
}

CreateUnit.layout = {
    breadcrumbs: [
        { title: 'Fleet', href: FleetController.index() },
        { title: 'Add unit', href: FleetController.create() },
    ],
};
