import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import AgreementController from '@/actions/App/Http/Controllers/Rentals/AgreementController';
import InspectionController from '@/actions/App/Http/Controllers/Rentals/InspectionController';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import InputError from '@/components/input-error';
import { Chip } from '@/components/yard/chip';
import { Combobox } from '@/components/yard/combobox';
import { Field, NativeSelect } from '@/components/yard/field';
import {
    Notice,
    PageBody,
    PageHeader,
    Section,
} from '@/components/yard/page-header';
import { RentalStatusChip } from '@/components/yard/rental-status';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatDate,
    formatDateTime,
    formatMoney,
    toLocalInputIn,
} from '@/lib/format';
import { getJson } from '@/lib/json';
import { cn } from '@/lib/utils';
import type {
    Option,
    RentalCustomer,
    RentalDetail,
    RentalEstimate,
    RentalProductOption,
} from '@/types';

type Props = {
    rental: RentalDetail | null;
    defaults: {
        starts_at: string;
        ends_at: string;
        rental_unit_id: number | null;
        product_id: number | null;
        customer_id: number | null;
    } | null;
    customers: RentalCustomer[];
    organizations: { id: number; name: string }[];
    products: RentalProductOption[];
    locations: { id: number; name: string; is_default: boolean }[];
    zones: { id: number; name: string; fee_cents: number }[];
    paymentMethods: Option[];
    tenders?: Option[];
    settings: { damage_waiver_percent: number; timezone: string };
};

type FormItem = { product_id: number; rental_unit_id: number | null };

type FormData = {
    organization_id: number | null;
    customer_id: number | null;
    location_id: number;
    starts_at: string;
    ends_at: string;
    fulfillment: 'pickup' | 'delivery';
    delivery_address: {
        line1: string;
        city: string;
        region: string;
        postal_code: string;
    };
    delivery_zone_id: number | null;
    damage_waiver: boolean;
    bill_fuel_cleaning: boolean;
    text_reminders: boolean;
    payment_method: string;
    notes: string;
    items: FormItem[];
};

export default function EditRental(props: Props) {
    const { rental } = props;

    if (rental && !rental.editable) {
        return <Contract {...props} rental={rental} />;
    }

    return <RentalForm {...props} />;
}

EditRental.layout = {
    breadcrumbs: [{ title: 'Rentals', href: RentalController.index() }],
};

// --------------------------------------------------------------------------
// New rental / edit quote or reservation (mockup 3)
// --------------------------------------------------------------------------

function RentalForm({
    rental,
    defaults,
    customers,
    products,
    locations,
    zones,
    paymentMethods,
    tenders,
    settings,
}: Props) {
    const tz = settings.timezone;
    const firstProduct =
        defaults?.product_id ??
        products.find((p) => p.units.length > 0)?.id ??
        products[0]?.id;

    const form = useForm<FormData>({
        organization_id: rental?.organization_id ?? null,
        customer_id: rental?.customer_id ?? defaults?.customer_id ?? null,
        location_id:
            rental?.location_id ??
            locations.find((l) => l.is_default)?.id ??
            locations[0]?.id ??
            0,
        starts_at: rental
            ? toLocalInputIn(rental.starts_at, tz)
            : (defaults?.starts_at ?? ''),
        ends_at: rental
            ? toLocalInputIn(rental.ends_at, tz)
            : (defaults?.ends_at ?? ''),
        fulfillment: rental?.fulfillment ?? 'pickup',
        delivery_address: {
            line1: rental?.delivery_address?.line1 ?? '',
            city: rental?.delivery_address?.city ?? '',
            region: rental?.delivery_address?.region ?? 'ON',
            postal_code: rental?.delivery_address?.postal_code ?? '',
        },
        delivery_zone_id: rental?.delivery_zone_id ?? null,
        damage_waiver: rental?.damage_waiver ?? true,
        bill_fuel_cleaning: rental?.bill_fuel_cleaning ?? true,
        text_reminders: rental?.text_reminders ?? true,
        payment_method: rental?.payment_method ?? 'card_on_file',
        notes: rental?.notes ?? '',
        items: rental
            ? rental.items.map((i) => ({
                  product_id: i.product_id,
                  rental_unit_id: i.rental_unit_id,
              }))
            : firstProduct
              ? [
                    {
                        product_id: firstProduct,
                        rental_unit_id: defaults?.rental_unit_id ?? null,
                    },
                ]
              : [],
    });

    const customer =
        customers.find((c) => c.id === form.data.customer_id) ?? null;
    const estimate = useEstimate(form.data, rental?.id ?? null);
    const errors = form.errors as Partial<Record<string, string>>;

    function setItem(index: number, item: FormItem) {
        form.setData(
            'items',
            form.data.items.map((existing, i) =>
                i === index ? item : existing,
            ),
        );
    }

    function submit(then?: 'agreement' | 'reserve' | 'signing-link') {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (!rental || !then) {
                    return;
                }

                if (then === 'reserve' || then === 'agreement') {
                    router.post(
                        RentalController.reserve.url(rental.id),
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                if (then === 'agreement') {
                                    router.visit(
                                        AgreementController.show.url(rental.id),
                                    );
                                }
                            },
                        },
                    );
                } else {
                    router.post(
                        RentalController.sendSigningLink.url(rental.id),
                        {},
                        { preserveScroll: true },
                    );
                }
            },
        };

        if (rental) {
            form.put(RentalController.update.url(rental.id), options);
        } else {
            form.post(RentalController.store.url(), options);
        }
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        submit();
    }

    const title = rental
        ? `${rental.status === 'quote' ? 'Quote' : 'Rental'} · ${rental.number}`
        : 'New rental';
    const lineFor = (index: number) => estimate?.lines?.[index];

    return (
        <>
            <Head title={rental ? rental.number : 'New rental'} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <>
                            <Link
                                href={RentalController.index()}
                                className="hover:underline"
                            >
                                Rentals
                            </Link>{' '}
                            / {rental ? rental.number : 'New'}
                        </>
                    }
                    title={
                        <span className="flex flex-wrap items-center gap-3">
                            {title}
                            {rental && (
                                <RentalStatusChip status={rental.status} />
                            )}
                        </span>
                    }
                />

                <InputError message={errors.rental} />

                <form
                    onSubmit={onSubmit}
                    className="flex flex-wrap items-start gap-5"
                >
                    <div className="flex min-w-0 flex-[999_1_560px] flex-col gap-4">
                        <Section title="Customer">
                            <Field
                                label="Search customers (shared with yard accounts)"
                                htmlFor="customer"
                                error={errors.customer_id}
                            >
                                <Combobox
                                    id="customer"
                                    value={form.data.customer_id}
                                    onChange={(id) => {
                                        const c = customers.find(
                                            (x) => x.id === id,
                                        );
                                        form.setData((data) => ({
                                            ...data,
                                            customer_id: id,
                                            organization_id:
                                                c?.organization_id ?? null,
                                        }));
                                    }}
                                    placeholder="Name, company or phone"
                                    options={customers.map((c) => ({
                                        value: c.id,
                                        label: c.organization
                                            ? `${c.organization} · ${c.name}`
                                            : c.name,
                                        detail: c.phone,
                                        keywords: `${c.email ?? ''}`,
                                    }))}
                                    empty="No customer with that name."
                                    footer={
                                        <Link
                                            href={CustomerController.create()}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            Add a new customer
                                        </Link>
                                    }
                                />
                            </Field>
                            {customer && <CustomerChips customer={customer} />}
                        </Section>

                        <Section title="Equipment and dates">
                            <div className="grid gap-3">
                                {form.data.items.map((item, index) => {
                                    const product = products.find(
                                        (p) => p.id === item.product_id,
                                    );
                                    const line = lineFor(index);

                                    return (
                                        <div
                                            key={index}
                                            className="flex items-end gap-2"
                                        >
                                            <Field
                                                label={
                                                    index === 0
                                                        ? 'Unit'
                                                        : `Unit ${index + 1}`
                                                }
                                                htmlFor={`unit-${index}`}
                                                className="flex-1"
                                            >
                                                <NativeSelect
                                                    id={`unit-${index}`}
                                                    value={`${item.product_id}:${item.rental_unit_id ?? ''}`}
                                                    onChange={(e) => {
                                                        const [p, u] =
                                                            e.target.value.split(
                                                                ':',
                                                            );
                                                        setItem(index, {
                                                            product_id:
                                                                Number(p),
                                                            rental_unit_id: u
                                                                ? Number(u)
                                                                : null,
                                                        });
                                                    }}
                                                >
                                                    {products.map((p) => (
                                                        <optgroup
                                                            key={p.id}
                                                            label={p.name}
                                                        >
                                                            <option
                                                                value={`${p.id}:`}
                                                            >
                                                                {p.name} · first
                                                                free unit
                                                            </option>
                                                            {p.units.map(
                                                                (u) => (
                                                                    <option
                                                                        key={
                                                                            u.id
                                                                        }
                                                                        value={`${p.id}:${u.id}`}
                                                                        disabled={
                                                                            u.status !==
                                                                            'available'
                                                                        }
                                                                    >
                                                                        {p.name}{' '}
                                                                        ·{' '}
                                                                        {
                                                                            u.serial
                                                                        }
                                                                        {u.status ===
                                                                        'in_shop'
                                                                            ? ' (in shop)'
                                                                            : u.service_due
                                                                              ? ' (service due)'
                                                                              : ''}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </optgroup>
                                                    ))}
                                                </NativeSelect>
                                                {product &&
                                                    line?.serial &&
                                                    !item.rental_unit_id && (
                                                        <p className="text-xs text-muted-foreground">
                                                            Will use{' '}
                                                            {line.serial}.
                                                        </p>
                                                    )}
                                            </Field>
                                            {form.data.items.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Remove unit ${index + 1}`}
                                                    onClick={() =>
                                                        form.setData(
                                                            'items',
                                                            form.data.items.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </div>
                                    );
                                })}
                                <InputError message={errors.items} />
                                <div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            form.setData('items', [
                                                ...form.data.items,
                                                {
                                                    product_id:
                                                        products[0]?.id ?? 0,
                                                    rental_unit_id: null,
                                                },
                                            ])
                                        }
                                    >
                                        <Plus /> Add another unit
                                    </Button>
                                </div>
                            </div>

                            <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3">
                                <Field
                                    label="Out"
                                    htmlFor="starts_at"
                                    error={errors.starts_at}
                                >
                                    <Input
                                        id="starts_at"
                                        type="datetime-local"
                                        value={form.data.starts_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'starts_at',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Back"
                                    htmlFor="ends_at"
                                    error={errors.ends_at}
                                >
                                    <Input
                                        id="ends_at"
                                        type="datetime-local"
                                        value={form.data.ends_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'ends_at',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                                {locations.length > 1 && (
                                    <Field label="Yard" htmlFor="location_id">
                                        <NativeSelect
                                            id="location_id"
                                            value={form.data.location_id}
                                            onChange={(e) =>
                                                form.setData(
                                                    'location_id',
                                                    Number(e.target.value),
                                                )
                                            }
                                        >
                                            {locations.map((l) => (
                                                <option key={l.id} value={l.id}>
                                                    {l.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                )}
                            </div>

                            <AvailabilityNotice estimate={estimate} />

                            <ToggleButtons
                                label="Pickup or delivery"
                                value={form.data.fulfillment}
                                onChange={(value) =>
                                    form.setData('fulfillment', value)
                                }
                                options={[
                                    {
                                        value: 'pickup',
                                        label: 'Customer picks up',
                                    },
                                    {
                                        value: 'delivery',
                                        label: 'We deliver and pick up',
                                    },
                                ]}
                            />

                            {form.data.fulfillment === 'delivery' && (
                                <div className="grid gap-3 sm:grid-cols-6">
                                    <Field
                                        label="Street"
                                        htmlFor="d_line1"
                                        error={errors['delivery_address.line1']}
                                        className="sm:col-span-4"
                                    >
                                        <Input
                                            id="d_line1"
                                            value={
                                                form.data.delivery_address.line1
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'delivery_address',
                                                    {
                                                        ...form.data
                                                            .delivery_address,
                                                        line1: e.target.value,
                                                    },
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Postal code"
                                        htmlFor="d_postal"
                                        error={
                                            errors[
                                                'delivery_address.postal_code'
                                            ]
                                        }
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="d_postal"
                                            value={
                                                form.data.delivery_address
                                                    .postal_code
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'delivery_address',
                                                    {
                                                        ...form.data
                                                            .delivery_address,
                                                        postal_code:
                                                            e.target.value,
                                                    },
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="City"
                                        htmlFor="d_city"
                                        className="sm:col-span-3"
                                    >
                                        <Input
                                            id="d_city"
                                            value={
                                                form.data.delivery_address.city
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'delivery_address',
                                                    {
                                                        ...form.data
                                                            .delivery_address,
                                                        city: e.target.value,
                                                    },
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Delivery zone"
                                        htmlFor="zone"
                                        className="sm:col-span-3"
                                        hint={
                                            !form.data.delivery_zone_id &&
                                            estimate?.delivery_zone
                                                ? `Matched ${estimate.delivery_zone.name} from the postal code.`
                                                : undefined
                                        }
                                    >
                                        <NativeSelect
                                            id="zone"
                                            value={
                                                form.data.delivery_zone_id ?? ''
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'delivery_zone_id',
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : null,
                                                )
                                            }
                                        >
                                            <option value="">
                                                From the postal code
                                            </option>
                                            {zones.map((z) => (
                                                <option key={z.id} value={z.id}>
                                                    {z.name} ·{' '}
                                                    {formatMoney(z.fee_cents)}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                </div>
                            )}
                        </Section>

                        <Section title="Protection and charges on return">
                            <div className="grid gap-1">
                                <BigCheckbox
                                    id="damage_waiver"
                                    checked={form.data.damage_waiver}
                                    onChange={(v) =>
                                        form.setData('damage_waiver', v)
                                    }
                                    label={`Damage waiver, ${trimPercent(settings.damage_waiver_percent)}% of rental (customer can decline with own insurance)`}
                                />
                                <BigCheckbox
                                    id="bill_fuel_cleaning"
                                    checked={form.data.bill_fuel_cleaning}
                                    onChange={(v) =>
                                        form.setData('bill_fuel_cleaning', v)
                                    }
                                    label="Bill fuel and cleaning on return if needed"
                                />
                                <BigCheckbox
                                    id="text_reminders"
                                    checked={form.data.text_reminders}
                                    onChange={(v) =>
                                        form.setData('text_reminders', v)
                                    }
                                    label="Text reminders: day before return and when overdue"
                                />
                            </div>
                            <Field label="Notes" htmlFor="notes">
                                <Input
                                    id="notes"
                                    value={form.data.notes}
                                    placeholder="Site access, operator, anything the yard should know"
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </Field>
                        </Section>
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_340px] flex-col gap-3 rounded-[10px] border border-border bg-card p-5 lg:sticky lg:top-4">
                        <h2 className="text-base font-semibold">Summary</h2>
                        <SummaryLines estimate={estimate} />

                        <DepositBox
                            cents={
                                estimate?.deposit_cents ??
                                rental?.deposit_cents ??
                                0
                            }
                            card={customer?.card ?? null}
                            status={rental?.deposit_status}
                        />

                        <Field
                            label="Payment"
                            htmlFor="payment_method"
                            error={errors.payment_method}
                        >
                            <NativeSelect
                                id="payment_method"
                                value={form.data.payment_method}
                                onChange={(e) =>
                                    form.setData(
                                        'payment_method',
                                        e.target.value,
                                    )
                                }
                            >
                                {paymentMethods.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>

                        {!rental && (
                            <>
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={form.processing}
                                >
                                    Save quote
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    Save first, then create the agreement for
                                    signing or text the link.
                                </p>
                            </>
                        )}

                        {rental?.status === 'quote' && (
                            <>
                                <Button
                                    type="button"
                                    size="lg"
                                    disabled={form.processing}
                                    onClick={() => submit('agreement')}
                                >
                                    Create agreement for signing
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={form.processing}
                                    onClick={() => submit('signing-link')}
                                >
                                    Text signing link to customer
                                </Button>
                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        className="flex-1"
                                        disabled={form.processing}
                                    >
                                        Save quote
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="flex-1"
                                        disabled={form.processing}
                                        onClick={() => submit('reserve')}
                                    >
                                        Reserve without signing
                                    </Button>
                                </div>
                            </>
                        )}

                        {rental?.status === 'reserved' && (
                            <ReservedActions
                                rental={rental}
                                form={form}
                                tenders={tenders ?? []}
                            />
                        )}

                        {rental && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() => {
                                    if (
                                        confirm(
                                            `Cancel ${rental.number}? Payments are refunded and holds released.`,
                                        )
                                    ) {
                                        router.post(
                                            RentalController.cancel.url(
                                                rental.id,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                            >
                                Cancel rental
                            </Button>
                        )}

                        <p className="text-xs text-muted-foreground">
                            Rental revenue syncs to QuickBooks Online. It never
                            goes through Shopify.
                        </p>
                    </aside>
                </form>
            </PageBody>
        </>
    );
}

function ReservedActions({
    rental,
    form,
    tenders,
}: {
    rental: RentalDetail;
    form: ReturnType<typeof useForm<FormData>>;
    tenders: Option[];
}) {
    const signed = rental.agreement?.signed_at != null;

    return (
        <>
            {!signed ? (
                <Button asChild size="lg">
                    <Link href={AgreementController.show(rental.id)}>
                        Open agreement for signing
                    </Link>
                </Button>
            ) : (
                <Button asChild size="lg">
                    <Link
                        href={InspectionController.show({
                            rental: rental.id,
                            kind: 'check-out',
                        })}
                    >
                        Check out equipment
                    </Link>
                </Button>
            )}
            {!signed && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            RentalController.sendSigningLink.url(rental.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Text signing link to customer
                </Button>
            )}
            <Button type="submit" variant="ghost" disabled={form.processing}>
                Save changes
            </Button>
            <PaymentPanel rental={rental} tenders={tenders} />
        </>
    );
}

// --------------------------------------------------------------------------
// Contract view: out, overdue, returned, cancelled
// --------------------------------------------------------------------------

function Contract({
    rental,
    tenders,
    settings,
}: Props & { rental: RentalDetail }) {
    const tz = settings.timezone;
    const out = rental.status === 'out' || rental.status === 'overdue';

    return (
        <>
            <Head title={rental.number} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <>
                            <Link
                                href={RentalController.index()}
                                className="hover:underline"
                            >
                                Rentals
                            </Link>{' '}
                            / {rental.number}
                        </>
                    }
                    title={
                        <span className="flex flex-wrap items-center gap-3">
                            {rental.organization?.name ??
                                rental.customer?.name ??
                                'Walk-in'}{' '}
                            · {rental.number}
                            <RentalStatusChip status={rental.status} />
                        </span>
                    }
                    description={`Out ${formatDateTime(rental.checked_out_at ?? rental.starts_at, tz)} · ${rental.returned_at ? `returned ${formatDateTime(rental.returned_at, tz)}` : `due back ${formatDateTime(rental.ends_at, tz)}`}`}
                    actions={
                        out && (
                            <Button asChild size="lg">
                                <Link
                                    href={InspectionController.show({
                                        rental: rental.id,
                                        kind: 'return',
                                    })}
                                >
                                    Check in
                                </Link>
                            </Button>
                        )
                    }
                />

                {rental.status === 'overdue' && (
                    <Notice tone="danger">
                        Overdue since {formatDateTime(rental.ends_at, tz)}. Late
                        time is charged when it’s checked in.
                    </Notice>
                )}

                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[999_1_560px] flex-col gap-4">
                        <Section title="Equipment" flush>
                            <table className="w-full text-sm">
                                <tbody>
                                    {rental.items.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="border-t border-secondary"
                                        >
                                            <td className="px-5 py-3">
                                                <div className="font-semibold">
                                                    {item.description}
                                                </div>
                                                <div className="figure text-xs text-muted-foreground">
                                                    {item.serial}
                                                </div>
                                            </td>
                                            <td className="px-5 py-3 text-subtle">
                                                {item.rate_summary}
                                            </td>
                                            <td className="figure px-5 py-3 text-right text-subtle">
                                                {item.hours_out
                                                    ? `${Number(item.hours_out)} hrs out`
                                                    : ''}
                                                {item.hours_in
                                                    ? ` · ${Number(item.hours_in)} in`
                                                    : ''}
                                            </td>
                                            <td className="figure px-5 py-3 text-right">
                                                {formatMoney(item.charge_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Section>

                        {rental.charges.length > 0 && (
                            <Section title="Charges on return">
                                <div className="grid gap-2">
                                    {rental.charges.map((c) => (
                                        <div
                                            key={c.id}
                                            className="flex justify-between gap-3"
                                        >
                                            <span>{c.description}</span>
                                            <span className="figure">
                                                {formatMoney(
                                                    c.covered_by_waiver
                                                        ? 0
                                                        : c.amount_cents,
                                                )}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </Section>
                        )}

                        <Inspections rental={rental} timezone={tz} />
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_340px] flex-col gap-3 rounded-[10px] border border-border bg-card p-5">
                        <h2 className="text-base font-semibold">Summary</h2>
                        <SummaryFromRental rental={rental} />
                        <DepositBox
                            cents={rental.deposit_cents}
                            card={rental.customer?.card ?? null}
                            status={rental.deposit_status}
                            capturedCents={rental.deposit_captured_cents}
                        />
                        {rental.agreement?.pdf_url && (
                            <a
                                href={rental.agreement.pdf_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                Signed agreement (PDF),{' '}
                                {rental.agreement.signer_name}
                            </a>
                        )}
                        {rental.status !== 'cancelled' && (
                            <PaymentPanel
                                rental={rental}
                                tenders={tenders ?? []}
                            />
                        )}
                    </aside>
                </div>
            </PageBody>
        </>
    );
}

function Inspections({
    rental,
    timezone,
}: {
    rental: RentalDetail;
    timezone: string;
}) {
    if (rental.inspections.length === 0) {
        return null;
    }

    return (
        <Section title="Inspections">
            <div className="grid gap-5">
                {rental.inspections.map((inspection) => (
                    <div key={inspection.id} className="grid gap-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <div className="font-semibold">
                                {inspection.kind === 'check_out'
                                    ? 'Check-out'
                                    : 'Return'}{' '}
                                · {inspection.serial}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {formatDateTime(
                                    inspection.created_at,
                                    timezone,
                                )}
                            </div>
                        </div>
                        <div className="text-sm text-subtle">
                            {[
                                inspection.hour_meter
                                    ? `${Number(inspection.hour_meter)} hrs`
                                    : null,
                                inspection.fuel
                                    ? `fuel ${inspection.fuel === 'half' ? '½' : inspection.fuel}`
                                    : null,
                                inspection.clean ? 'clean' : 'needs cleaning',
                                inspection.notes,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                        {inspection.photos.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                {inspection.photos.map((photo) => (
                                    <a
                                        key={photo.id}
                                        href={photo.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="relative block"
                                    >
                                        <img
                                            src={photo.url}
                                            alt={
                                                photo.label ??
                                                `Photo of ${inspection.serial}`
                                            }
                                            className={cn(
                                                'size-24 rounded-lg object-cover',
                                                photo.new_damage &&
                                                    'ring-2 ring-destructive',
                                            )}
                                        />
                                        {photo.label && (
                                            <span className="absolute inset-x-0 bottom-0 truncate rounded-b-lg bg-black/60 px-1.5 py-0.5 text-[11px] text-white">
                                                {photo.label}
                                            </span>
                                        )}
                                    </a>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </Section>
    );
}

// --------------------------------------------------------------------------
// Shared pieces
// --------------------------------------------------------------------------

function useEstimate(
    data: FormData,
    rentalId: number | null,
): RentalEstimate | null {
    const [estimate, setEstimate] = useState<RentalEstimate | null>(null);
    const key = JSON.stringify([
        data.items,
        data.starts_at,
        data.ends_at,
        data.location_id,
        data.fulfillment,
        data.delivery_address.postal_code,
        data.delivery_zone_id,
        data.damage_waiver,
        data.organization_id,
    ]);
    const controller = useRef<AbortController | null>(null);

    useEffect(() => {
        if (!data.starts_at || !data.ends_at || data.items.length === 0) {
            return;
        }

        const timer = setTimeout(() => {
            controller.current?.abort();
            controller.current = new AbortController();

            getJson<RentalEstimate>(
                RentalController.preview.url(),
                {
                    items: data.items,
                    starts_at: data.starts_at,
                    ends_at: data.ends_at,
                    location_id: data.location_id,
                    fulfillment: data.fulfillment,
                    delivery_address: data.delivery_address,
                    delivery_zone_id: data.delivery_zone_id,
                    damage_waiver: data.damage_waiver,
                    organization_id: data.organization_id,
                    rental_id: rentalId,
                },
                controller.current.signal,
            )
                .then(setEstimate)
                .catch(() => {});
        }, 250);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return estimate;
}

function AvailabilityNotice({ estimate }: { estimate: RentalEstimate | null }) {
    if (!estimate) {
        return null;
    }

    if (!estimate.valid || estimate.problems.length > 0) {
        return <Notice tone="danger">{estimate.problems.join(' ')}</Notice>;
    }

    return (
        <Notice tone="success">
            Available for these dates.
            {estimate.notes && estimate.notes.length > 0
                ? ` ${estimate.notes.join(' ')}`
                : ''}
        </Notice>
    );
}

function SummaryLines({ estimate }: { estimate: RentalEstimate | null }) {
    if (!estimate?.valid || !estimate.lines) {
        return (
            <p className="text-sm text-muted-foreground">
                Pick equipment and dates to see the price.
            </p>
        );
    }

    return (
        <div className="grid gap-2">
            {estimate.lines.map((line, i) => (
                <div key={i} className="grid gap-0.5">
                    <Row
                        label={`${capitalize(line.rate_summary)} rate${line.serial ? `, ${line.serial}` : ''}`}
                        cents={line.charge_cents}
                    />
                    {line.day_rate_equivalent_cents > line.charge_cents && (
                        <div className="flex justify-between text-[13px] text-muted-foreground">
                            <span>
                                {line.billable_days} days at daily rate would be
                            </span>
                            <span className="figure line-through">
                                {formatMoney(line.day_rate_equivalent_cents)}
                            </span>
                        </div>
                    )}
                </div>
            ))}
            {(estimate.waiver_cents ?? 0) > 0 && (
                <Row label="Damage waiver" cents={estimate.waiver_cents ?? 0} />
            )}
            {(estimate.delivery_fee_cents ?? 0) > 0 && (
                <Row
                    label={`Delivery and pickup, ${estimate.delivery_zone?.name ?? 'zone'}`}
                    cents={estimate.delivery_fee_cents ?? 0}
                />
            )}
            <Row label="Subtotal" cents={estimate.subtotal_cents ?? 0} ruled />
            <Row label="Tax" cents={estimate.tax_cents ?? 0} />
            {estimate.tax_note && (
                <p className="text-xs text-[#a16207]">{estimate.tax_note}</p>
            )}
            <Row label="Total" cents={estimate.total_cents ?? 0} strong />
        </div>
    );
}

function SummaryFromRental({ rental }: { rental: RentalDetail }) {
    return (
        <div className="grid gap-2">
            <Row label="Rental" cents={rental.rental_cents} />
            {rental.waiver_cents > 0 && (
                <Row label="Damage waiver" cents={rental.waiver_cents} />
            )}
            {rental.delivery_fee_cents > 0 && (
                <Row
                    label={`Delivery and pickup${rental.delivery_zone ? `, ${rental.delivery_zone.name}` : ''}`}
                    cents={rental.delivery_fee_cents}
                />
            )}
            {rental.charges_cents > 0 && (
                <Row label="Charges on return" cents={rental.charges_cents} />
            )}
            <Row label="Subtotal" cents={rental.subtotal_cents} ruled />
            <Row label="Tax" cents={rental.tax_cents} />
            <Row label="Total" cents={rental.total_cents} strong />
            <Row label="Paid" cents={rental.amount_paid_cents} />
            {rental.balance_cents > 0 && (
                <Row
                    label="Balance owing"
                    cents={rental.balance_cents}
                    strong
                />
            )}
        </div>
    );
}

function Row({
    label,
    cents,
    ruled,
    strong,
}: {
    label: string;
    cents: number;
    ruled?: boolean;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex justify-between gap-3',
                ruled && 'border-t border-border pt-2',
                strong && 'text-lg font-bold',
            )}
        >
            <span>{label}</span>
            <span className="figure">{formatMoney(cents)}</span>
        </div>
    );
}

function DepositBox({
    cents,
    card,
    status,
    capturedCents = 0,
}: {
    cents: number;
    card: string | null;
    status?: RentalDetail['deposit_status'];
    capturedCents?: number;
}) {
    if (cents <= 0) {
        return null;
    }

    const detail =
        status === 'held'
            ? `Held${card ? ` on ${card}` : ''}, not charged. Released on clean return.`
            : status === 'released'
              ? 'Released.'
              : status === 'captured'
                ? `${formatMoney(capturedCents)} applied to charges, the rest released.`
                : card
                  ? `Held on ${card} at pickup, not charged. Released on clean return.`
                  : 'Held on the customer’s card at pickup, not charged. Released on clean return.';

    return (
        <div className="grid gap-1 rounded-lg bg-background p-3">
            <div className="font-semibold">
                Security deposit: {formatMoney(cents)} hold
            </div>
            <div className="text-[13px] text-muted-foreground">{detail}</div>
        </div>
    );
}

function PaymentPanel({
    rental,
    tenders,
}: {
    rental: RentalDetail;
    tenders: Option[];
}) {
    const form = useForm({
        tender: tenders[0]?.value ?? 'card_terminal',
        amount: (rental.balance_cents / 100).toFixed(2),
        reference: '',
    });
    const link = rental.payments.find(
        (p) => p.tender === 'payment_link' && p.status === 'pending',
    );
    const card = rental.customer?.card;

    return (
        <div className="grid gap-3 border-t border-border pt-3">
            <div className="flex items-baseline justify-between">
                <span className="font-semibold">Payment</span>
                {rental.balance_cents > 0 ? (
                    <Chip tone="warning">
                        {formatMoney(rental.balance_cents)} owing
                    </Chip>
                ) : (
                    <Chip tone="success">Paid</Chip>
                )}
            </div>

            {rental.balance_cents > 0 && (
                <>
                    {card && (
                        <Button
                            type="button"
                            onClick={() =>
                                router.post(
                                    RentalController.chargeCard.url(rental.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Charge {formatMoney(rental.balance_cents)} to {card}
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                RentalController.paymentLink.url(rental.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {link ? 'New payment link' : 'Create payment link'}
                    </Button>
                    {link?.checkout_url && (
                        <div className="text-sm">
                            <a
                                href={link.checkout_url}
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium text-primary hover:underline"
                            >
                                Open payment page
                            </a>
                            <button
                                type="button"
                                className="ml-3 text-muted-foreground hover:underline"
                                onClick={() =>
                                    navigator.clipboard.writeText(
                                        link.checkout_url ?? '',
                                    )
                                }
                            >
                                Copy link
                            </button>
                        </div>
                    )}
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                RentalController.recordPayment.url(rental.id),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <Field
                                label="Paid by"
                                htmlFor="tender"
                                error={form.errors.tender}
                            >
                                <NativeSelect
                                    id="tender"
                                    value={form.data.tender}
                                    onChange={(e) =>
                                        form.setData('tender', e.target.value)
                                    }
                                >
                                    {tenders.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Amount"
                                htmlFor="amount"
                                error={form.errors.amount}
                            >
                                <Input
                                    id="amount"
                                    inputMode="decimal"
                                    value={form.data.amount}
                                    onChange={(e) =>
                                        form.setData('amount', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        <Button
                            type="submit"
                            variant="ghost"
                            disabled={form.processing}
                        >
                            Record counter payment
                        </Button>
                    </form>
                </>
            )}

            {rental.payments.filter((p) => p.status === 'succeeded').length >
                0 && (
                <ul className="grid gap-1 text-[13px]">
                    {rental.payments
                        .filter((p) => p.status === 'succeeded')
                        .map((p) => (
                            <li
                                key={p.id}
                                className="flex justify-between gap-3 text-muted-foreground"
                            >
                                <span>
                                    {paymentLabel(p.kind)} ·{' '}
                                    {formatDate(p.succeeded_at)}
                                </span>
                                <span className="figure">
                                    {formatMoney(p.amount_cents)}
                                </span>
                            </li>
                        ))}
                </ul>
            )}
        </div>
    );
}

function CustomerChips({ customer }: { customer: RentalCustomer }) {
    return (
        <div className="flex flex-wrap gap-2">
            {customer.licence ? (
                <Chip tone="success">Driver’s licence on file</Chip>
            ) : (
                <Chip tone="warning">No licence on file</Chip>
            )}
            {customer.insurance_until ? (
                <Chip tone="success">
                    Insurance certificate{' '}
                    {customer.insurance_until === 'on file'
                        ? 'on file'
                        : `to ${formatDate(customer.insurance_until)}`}
                </Chip>
            ) : (
                <Chip tone="neutral">No insurance certificate</Chip>
            )}
            <Chip tone="neutral">
                {customer.card
                    ? `Card on file · ${customer.card}`
                    : 'No card on file'}
            </Chip>
            <Link
                href={CustomerController.edit(customer.id)}
                className="self-center text-sm text-primary hover:underline"
            >
                Customer file
            </Link>
        </div>
    );
}

function BigCheckbox({
    id,
    checked,
    onChange,
    label,
}: {
    id: string;
    checked: boolean;
    onChange: (v: boolean) => void;
    label: string;
}) {
    return (
        <label htmlFor={id} className="flex min-h-11 items-center gap-2.5">
            <input
                id={id}
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="size-5 accent-primary"
            />
            <span>{label}</span>
        </label>
    );
}

function paymentLabel(kind: string): string {
    return (
        {
            charge: 'Payment',
            deposit_hold: 'Deposit held',
            deposit_release: 'Deposit released',
            deposit_capture: 'Deposit applied',
            refund: 'Refund',
        }[kind] ?? kind
    );
}

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function trimPercent(value: number): string {
    return String(Number(value.toFixed(2)));
}
