import type { InertiaFormProps } from '@inertiajs/react';
import { Field, NativeSelect } from '@/components/yard/field';
import { Input } from '@/components/ui/input';
import type { DeliveryAddress } from '@/types';

export type OrderHeaderData = {
    organization_id: string;
    customer_id: string;
    organization_location_id: string;
    location_id: string;
    fulfillment: 'pickup' | 'delivery';
    delivery_address: DeliveryAddress;
    requested_on: string;
    po_number: string;
    rental_starts_at: string;
    rental_ends_at: string;
    notes: string;
    delivery_km?: string;
    delivery_fee?: string;
    needs_boom?: boolean;
};

type Org = { id: number; name: string };
export type JobSite = {
    id: number;
    name: string;
    address: string;
    delivery_address: DeliveryAddress;
    po_required: boolean;
    location_id: number | null;
    delivery_notes: string | null;
};
type Person = {
    id: number;
    organization_id: number | null;
    first_name: string;
    last_name: string | null;
};

/**
 * Who, where and when: shared by "new order" and the draft order page.
 */
export function OrderHeaderFields<T extends OrderHeaderData>({
    form,
    isRental,
    organizations,
    customers,
    locations,
    sites = [],
}: {
    form: InertiaFormProps<T>;
    isRental: boolean;
    organizations: Org[];
    customers: Person[];
    locations: { id: number; name: string }[];
    sites?: JobSite[];
}) {
    const data = form.data as OrderHeaderData;
    const errors = form.errors as Partial<Record<string, string>>;
    const set = (key: keyof OrderHeaderData, value: unknown) =>
        (form.setData as (key: string, value: unknown) => void)(key, value);

    const people = data.organization_id
        ? customers.filter(
              (c) => String(c.organization_id) === data.organization_id,
          )
        : customers;

    const address = (key: keyof DeliveryAddress, value: string) =>
        set('delivery_address', { ...data.delivery_address, [key]: value });

    return (
        <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Organization"
                    htmlFor="organization_id"
                    error={errors.organization_id}
                    hint={
                        isRental
                            ? undefined
                            : 'Blank for walk-ins, which are prepaid.'
                    }
                >
                    <NativeSelect
                        id="organization_id"
                        value={data.organization_id}
                        onChange={(e) => {
                            set('organization_id', e.target.value);
                            set('customer_id', '');
                            set('organization_location_id', '');
                        }}
                    >
                        <option value="">Walk-in</option>
                        {organizations.map((org) => (
                            <option key={org.id} value={org.id}>
                                {org.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Contact"
                    htmlFor="customer_id"
                    error={errors.customer_id}
                >
                    <NativeSelect
                        id="customer_id"
                        value={data.customer_id}
                        onChange={(e) => set('customer_id', e.target.value)}
                    >
                        <option value="">None</option>
                        {people.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.first_name} {c.last_name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            </div>

            {sites.length > 0 && String(data.organization_id) !== '' && (
                <Field
                    label="Job site"
                    htmlFor="organization_location_id"
                    error={errors.organization_location_id}
                    hint={(() => {
                        const site = sites.find(
                            (s) =>
                                String(s.id) === data.organization_location_id,
                        );

                        return site
                            ? [
                                  site.address,
                                  site.po_required ? 'PO required' : null,
                                  site.delivery_notes,
                              ]
                                  .filter(Boolean)
                                  .join(' · ')
                            : undefined;
                    })()}
                >
                    <NativeSelect
                        id="organization_location_id"
                        value={data.organization_location_id}
                        onChange={(e) => {
                            const site = sites.find(
                                (s) => String(s.id) === e.target.value,
                            );
                            set('organization_location_id', e.target.value);

                            if (site) {
                                if (site.location_id) {
                                    set(
                                        'location_id',
                                        String(site.location_id),
                                    );
                                }

                                if (site.delivery_address.line1) {
                                    set('fulfillment', 'delivery');
                                    set(
                                        'delivery_address',
                                        site.delivery_address,
                                    );
                                }
                            }
                        }}
                    >
                        <option value="">None</option>
                        {sites.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                <Field
                    label="Yard"
                    htmlFor="location_id"
                    error={errors.location_id}
                >
                    <NativeSelect
                        id="location_id"
                        value={data.location_id}
                        onChange={(e) => set('location_id', e.target.value)}
                        required
                    >
                        {locations.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Fulfillment"
                    htmlFor="fulfillment"
                    error={errors.fulfillment}
                >
                    <NativeSelect
                        id="fulfillment"
                        value={data.fulfillment}
                        onChange={(e) => set('fulfillment', e.target.value)}
                    >
                        <option value="pickup">Pickup at yard</option>
                        <option value="delivery">Delivery</option>
                    </NativeSelect>
                </Field>
                {isRental ? null : (
                    <Field
                        label="Wanted on"
                        htmlFor="requested_on"
                        error={errors.requested_on}
                    >
                        <Input
                            id="requested_on"
                            type="date"
                            value={data.requested_on}
                            onChange={(e) =>
                                set('requested_on', e.target.value)
                            }
                        />
                    </Field>
                )}
            </div>

            {isRental && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Out"
                        htmlFor="rental_starts_at"
                        error={errors.rental_starts_at}
                    >
                        <Input
                            id="rental_starts_at"
                            type="datetime-local"
                            value={data.rental_starts_at}
                            onChange={(e) =>
                                set('rental_starts_at', e.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="Due back"
                        htmlFor="rental_ends_at"
                        error={errors.rental_ends_at}
                    >
                        <Input
                            id="rental_ends_at"
                            type="datetime-local"
                            value={data.rental_ends_at}
                            onChange={(e) =>
                                set('rental_ends_at', e.target.value)
                            }
                            required
                        />
                    </Field>
                </div>
            )}

            {data.fulfillment === 'delivery' && (
                <fieldset className="grid gap-4 rounded-md border border-border p-4 sm:grid-cols-6">
                    <legend className="px-1 text-sm font-medium">
                        Deliver to
                    </legend>
                    <Field
                        label="Street"
                        htmlFor="delivery_line1"
                        error={errors['delivery_address.line1']}
                        className="sm:col-span-6"
                    >
                        <Input
                            id="delivery_line1"
                            value={data.delivery_address.line1 ?? ''}
                            onChange={(e) => address('line1', e.target.value)}
                        />
                    </Field>
                    <Field
                        label="City"
                        htmlFor="delivery_city"
                        className="sm:col-span-3"
                    >
                        <Input
                            id="delivery_city"
                            value={data.delivery_address.city ?? ''}
                            onChange={(e) => address('city', e.target.value)}
                        />
                    </Field>
                    <Field
                        label="Province"
                        htmlFor="delivery_region"
                        className="sm:col-span-1"
                    >
                        <Input
                            id="delivery_region"
                            value={data.delivery_address.region ?? ''}
                            onChange={(e) => address('region', e.target.value)}
                        />
                    </Field>
                    <Field
                        label="Postal code"
                        htmlFor="delivery_postal"
                        error={errors['delivery_address.postal_code']}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="delivery_postal"
                            value={data.delivery_address.postal_code ?? ''}
                            onChange={(e) =>
                                address('postal_code', e.target.value)
                            }
                        />
                    </Field>
                </fieldset>
            )}

            <div className="grid gap-4 sm:grid-cols-[12rem_1fr]">
                <Field
                    label="PO number"
                    htmlFor="po_number"
                    error={errors.po_number}
                >
                    <Input
                        id="po_number"
                        value={data.po_number}
                        onChange={(e) => set('po_number', e.target.value)}
                    />
                </Field>
                <Field label="Notes" htmlFor="notes" error={errors.notes}>
                    <Input
                        id="notes"
                        value={data.notes}
                        placeholder="Gate code, loading instructions"
                        onChange={(e) => set('notes', e.target.value)}
                    />
                </Field>
            </div>
        </div>
    );
}

/** Format a server datetime for a datetime-local input. */
export function toLocalInput(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Strip fields the server rejects for this kind of order. */
export function headerPayload(data: OrderHeaderData, isRental: boolean) {
    return {
        ...data,
        organization_id: data.organization_id || null,
        customer_id: data.customer_id || null,
        organization_location_id: data.organization_location_id || null,
        delivery_address:
            data.fulfillment === 'delivery'
                ? { country: 'CA', ...data.delivery_address }
                : null,
        requested_on: isRental ? null : data.requested_on || null,
        rental_starts_at: isRental ? data.rental_starts_at : null,
        rental_ends_at: isRental ? data.rental_ends_at : null,
    };
}
