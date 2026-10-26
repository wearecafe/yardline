import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ContractPriceController from '@/actions/App/Http/Controllers/ContractPriceController';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import OrganizationContactController from '@/actions/App/Http/Controllers/OrganizationContactController';
import OrganizationLocationController from '@/actions/App/Http/Controllers/OrganizationLocationController';
import { Chip } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney } from '@/lib/format';
import type { Option } from '@/types';

export type OrgContact = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    role: string;
    can_charge_account: boolean;
    primary: boolean;
};

export type OrgSite = {
    id: number;
    name: string;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    region: string | null;
    postal_code: string | null;
    country: string;
    delivery_notes: string | null;
    location_id: number | null;
    po_required: boolean;
    is_default: boolean;
    is_active: boolean;
    yard: string | null;
};

export type OrgContractPrice = {
    id: number;
    product: string;
    sku: string;
    unit: string | null;
    price_cents: number;
    starts_on: string | null;
    ends_on: string | null;
    in_effect: boolean;
    note: string | null;
};

/**
 * Contacts with their role in this organization and whether they can
 * charge to the account.
 */
export function ContactsSection({
    organizationId,
    contacts,
    roles,
    otherCustomers,
}: {
    organizationId: number;
    contacts: OrgContact[];
    roles: Option[];
    otherCustomers: { id: number; name: string }[];
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({
        customer_id: '',
        role: 'buyer',
        can_charge_account: true,
    });

    const change = (
        contact: OrgContact,
        patch: Partial<Pick<OrgContact, 'role' | 'can_charge_account'>>,
    ) =>
        router.patch(
            OrganizationContactController.update.url({
                organization: organizationId,
                customer: contact.id,
            }),
            {
                role: contact.role,
                can_charge_account: contact.can_charge_account,
                ...patch,
            },
            { preserveScroll: true },
        );

    return (
        <Section
            title="Contacts"
            actions={
                <span className="flex gap-3 text-sm">
                    {otherCustomers.length > 0 && (
                        <button
                            type="button"
                            className="text-muted-foreground underline-offset-4 hover:underline"
                            onClick={() => setAdding(!adding)}
                        >
                            Link existing
                        </button>
                    )}
                    <Link
                        href={CustomerController.create({
                            query: { organization_id: organizationId },
                        })}
                        className="text-muted-foreground underline-offset-4 hover:underline"
                    >
                        New contact
                    </Link>
                </span>
            }
        >
            {adding && (
                <form
                    className="grid gap-3 rounded-lg border border-dashed border-border p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(
                            OrganizationContactController.store.url(
                                organizationId,
                            ),
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    setAdding(false);
                                },
                            },
                        );
                    }}
                >
                    <Field
                        label="Person"
                        htmlFor="link_customer"
                        error={form.errors.customer_id}
                    >
                        <NativeSelect
                            id="link_customer"
                            required
                            value={form.data.customer_id}
                            onChange={(e) =>
                                form.setData('customer_id', e.target.value)
                            }
                        >
                            <option value="">Pick a contact</option>
                            {otherCustomers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Role" htmlFor="link_role">
                        <NativeSelect
                            id="link_role"
                            value={form.data.role}
                            onChange={(e) =>
                                form.setData('role', e.target.value)
                            }
                        >
                            {roles.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Checkbox
                        id="link_charge"
                        label="Can charge to the account"
                        checked={form.data.can_charge_account}
                        onChange={(v) => form.setData('can_charge_account', v)}
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing}
                    >
                        Add to this account
                    </Button>
                </form>
            )}
            {contacts.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No contacts yet.
                </p>
            ) : (
                <ul className="grid gap-4 text-sm">
                    {contacts.map((c) => (
                        <li key={c.id} className="grid gap-1.5">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <Link
                                        href={CustomerController.edit(c.id)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {c.name}
                                    </Link>
                                    <div className="text-muted-foreground">
                                        {[c.phone, c.email]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </div>
                                </div>
                                {!c.primary && (
                                    <button
                                        type="button"
                                        className="text-xs text-muted-foreground underline-offset-4 hover:underline"
                                        onClick={() =>
                                            router.delete(
                                                OrganizationContactController.destroy.url(
                                                    {
                                                        organization:
                                                            organizationId,
                                                        customer: c.id,
                                                    },
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Unlink
                                    </button>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <NativeSelect
                                    aria-label={`${c.name}'s role`}
                                    value={c.role}
                                    onChange={(e) =>
                                        change(c, { role: e.target.value })
                                    }
                                    className="h-9 w-auto text-sm"
                                >
                                    {roles.map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <Checkbox
                                    id={`charge-${c.id}`}
                                    label="Can charge to account"
                                    checked={c.can_charge_account}
                                    onChange={(v) =>
                                        change(c, { can_charge_account: v })
                                    }
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function SiteForm({
    organizationId,
    site,
    yards,
    onDone,
}: {
    organizationId: number;
    site: OrgSite | null;
    yards: { id: number; name: string }[];
    onDone: () => void;
}) {
    const form = useForm({
        name: site?.name ?? '',
        address_line1: site?.address_line1 ?? '',
        city: site?.city ?? '',
        region: site?.region ?? 'ON',
        postal_code: site?.postal_code ?? '',
        delivery_notes: site?.delivery_notes ?? '',
        location_id: site?.location_id ? String(site.location_id) : '',
        po_required: site?.po_required ?? false,
        is_default: site?.is_default ?? false,
        is_active: site?.is_active ?? true,
    });
    const id = site?.id ?? 'new';

    return (
        <form
            className="grid gap-3 rounded-lg border border-dashed border-border p-4 sm:grid-cols-6"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    location_id: d.location_id || null,
                }));
                const options = { preserveScroll: true, onSuccess: onDone };

                if (site) {
                    form.put(
                        OrganizationLocationController.update.url({
                            organization: organizationId,
                            site: site.id,
                        }),
                        options,
                    );
                } else {
                    form.post(
                        OrganizationLocationController.store.url(
                            organizationId,
                        ),
                        options,
                    );
                }
            }}
        >
            <Field
                label="Site name"
                htmlFor={`site-name-${id}`}
                error={form.errors.name}
                className="sm:col-span-3"
            >
                <Input
                    id={`site-name-${id}`}
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="Lot 14, Mountainview subdivision"
                />
            </Field>
            <Field
                label="Usual yard"
                htmlFor={`site-yard-${id}`}
                className="sm:col-span-3"
            >
                <NativeSelect
                    id={`site-yard-${id}`}
                    value={form.data.location_id}
                    onChange={(e) =>
                        form.setData('location_id', e.target.value)
                    }
                >
                    <option value="">Any</option>
                    {yards.map((y) => (
                        <option key={y.id} value={y.id}>
                            {y.name}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field
                label="Street"
                htmlFor={`site-line1-${id}`}
                className="sm:col-span-3"
            >
                <Input
                    id={`site-line1-${id}`}
                    value={form.data.address_line1}
                    onChange={(e) =>
                        form.setData('address_line1', e.target.value)
                    }
                />
            </Field>
            <Field
                label="City"
                htmlFor={`site-city-${id}`}
                className="sm:col-span-1"
            >
                <Input
                    id={`site-city-${id}`}
                    value={form.data.city}
                    onChange={(e) => form.setData('city', e.target.value)}
                />
            </Field>
            <Field
                label="Prov."
                htmlFor={`site-region-${id}`}
                className="sm:col-span-1"
            >
                <Input
                    id={`site-region-${id}`}
                    value={form.data.region}
                    onChange={(e) => form.setData('region', e.target.value)}
                />
            </Field>
            <Field
                label="Postal code"
                htmlFor={`site-postal-${id}`}
                className="sm:col-span-1"
            >
                <Input
                    id={`site-postal-${id}`}
                    value={form.data.postal_code}
                    onChange={(e) =>
                        form.setData('postal_code', e.target.value)
                    }
                />
            </Field>
            <Field
                label="Delivery notes"
                htmlFor={`site-notes-${id}`}
                className="sm:col-span-6"
                hint="Shown to the driver: gate codes, where to drop, who to call."
            >
                <Input
                    id={`site-notes-${id}`}
                    value={form.data.delivery_notes}
                    onChange={(e) =>
                        form.setData('delivery_notes', e.target.value)
                    }
                />
            </Field>
            <div className="flex flex-wrap gap-5 sm:col-span-6">
                <Checkbox
                    id={`site-po-${id}`}
                    label="PO number required"
                    checked={form.data.po_required}
                    onChange={(v) => form.setData('po_required', v)}
                />
                <Checkbox
                    id={`site-default-${id}`}
                    label="Default site"
                    checked={form.data.is_default}
                    onChange={(v) => form.setData('is_default', v)}
                />
                {site && (
                    <Checkbox
                        id={`site-active-${id}`}
                        label="Active"
                        checked={form.data.is_active}
                        onChange={(v) => form.setData('is_active', v)}
                    />
                )}
            </div>
            <div className="flex gap-2 sm:col-span-6">
                <Button type="submit" disabled={form.processing}>
                    {site ? 'Save site' : 'Add site'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * Job sites and ship-to addresses, each with a usual yard and whether
 * orders need a PO.
 */
export function SitesSection({
    organizationId,
    sites,
    yards,
}: {
    organizationId: number;
    sites: OrgSite[];
    yards: { id: number; name: string }[];
}) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);

    return (
        <Section
            title="Job sites"
            actions={
                editing === null && (
                    <Button variant="outline" onClick={() => setEditing('new')}>
                        Add site
                    </Button>
                )
            }
        >
            {editing === 'new' && (
                <SiteForm
                    organizationId={organizationId}
                    site={null}
                    yards={yards}
                    onDone={() => setEditing(null)}
                />
            )}
            {sites.length === 0 && editing !== 'new' && (
                <p className="text-sm text-muted-foreground">
                    No job sites yet. Add one to fill in delivery addresses on
                    orders.
                </p>
            )}
            <ul className="grid gap-3 text-sm">
                {sites.map((site) =>
                    editing === site.id ? (
                        <li key={site.id}>
                            <SiteForm
                                organizationId={organizationId}
                                site={site}
                                yards={yards}
                                onDone={() => setEditing(null)}
                            />
                        </li>
                    ) : (
                        <li
                            key={site.id}
                            className="flex items-start justify-between gap-3"
                        >
                            <div>
                                <div className="flex flex-wrap items-center gap-2 font-medium">
                                    {site.name}
                                    {site.is_default && <Chip>Default</Chip>}
                                    {site.po_required && (
                                        <Chip tone="info">PO required</Chip>
                                    )}
                                    {!site.is_active && <Chip>Inactive</Chip>}
                                </div>
                                <div className="text-muted-foreground">
                                    {[
                                        site.address_line1,
                                        site.city,
                                        site.postal_code,
                                    ]
                                        .filter(Boolean)
                                        .join(', ') || 'No address'}
                                    {site.yard ? ` · from ${site.yard}` : ''}
                                </div>
                                {site.delivery_notes && (
                                    <div className="text-muted-foreground italic">
                                        {site.delivery_notes}
                                    </div>
                                )}
                            </div>
                            <div className="flex gap-1">
                                <Button
                                    variant="ghost"
                                    onClick={() => setEditing(site.id)}
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(
                                            OrganizationLocationController.destroy.url(
                                                {
                                                    organization:
                                                        organizationId,
                                                    site: site.id,
                                                },
                                            ),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        </li>
                    ),
                )}
            </ul>
        </Section>
    );
}

/**
 * Prices agreed with this organization, which beat its price list.
 */
export function ContractPricesSection({
    organizationId,
    prices,
    products,
}: {
    organizationId: number;
    prices: OrgContractPrice[];
    products: {
        id: number;
        label: string;
        units: { id: number; name: string; is_base: boolean }[];
    }[];
}) {
    const form = useForm({
        product_id: '',
        product_unit_id: '',
        price: '',
        starts_on: '',
        ends_on: '',
        note: '',
    });
    const product = products.find((p) => String(p.id) === form.data.product_id);

    return (
        <Section
            title="Contract prices"
            description="Agreed prices for this account. They beat the price list while they're in effect."
            flush
        >
            <Table minWidth={620}>
                <thead>
                    <tr>
                        <Th>Product</Th>
                        <Th>Unit</Th>
                        <Th>In effect</Th>
                        <Th numeric>Price</Th>
                        <Th className="w-10">
                            <span className="sr-only">Remove</span>
                        </Th>
                    </tr>
                </thead>
                <tbody>
                    {prices.length === 0 && (
                        <EmptyRow colSpan={5}>No contract prices.</EmptyRow>
                    )}
                    {prices.map((p) => (
                        <tr key={p.id}>
                            <Td>
                                <div className="font-medium">{p.product}</div>
                                <div className="figure text-xs text-muted-foreground">
                                    {p.sku}
                                    {p.note ? ` · ${p.note}` : ''}
                                </div>
                            </Td>
                            <Td>{p.unit ?? 'Base unit'}</Td>
                            <Td className="whitespace-nowrap">
                                {p.starts_on || p.ends_on
                                    ? `${p.starts_on ? formatDate(p.starts_on) : 'Now'} – ${p.ends_on ? formatDate(p.ends_on) : 'ongoing'}`
                                    : 'Ongoing'}
                                {!p.in_effect && (
                                    <Chip className="ml-2">Not now</Chip>
                                )}
                            </Td>
                            <Td numeric>{formatMoney(p.price_cents)}</Td>
                            <Td>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(
                                            ContractPriceController.destroy.url(
                                                {
                                                    organization:
                                                        organizationId,
                                                    contractPrice: p.id,
                                                },
                                            ),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
            <form
                className="grid gap-3 border-t border-border p-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_7rem_9rem_9rem_auto] sm:items-end"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({
                        ...d,
                        product_unit_id: d.product_unit_id || null,
                        starts_on: d.starts_on || null,
                        ends_on: d.ends_on || null,
                    }));
                    form.post(
                        ContractPriceController.store.url(organizationId),
                        { preserveScroll: true, onSuccess: () => form.reset() },
                    );
                }}
            >
                <Field
                    label="Product"
                    htmlFor="cp_product"
                    error={form.errors.product_id}
                >
                    <NativeSelect
                        id="cp_product"
                        required
                        value={form.data.product_id}
                        onChange={(e) =>
                            form.setData((d) => ({
                                ...d,
                                product_id: e.target.value,
                                product_unit_id: '',
                            }))
                        }
                    >
                        <option value="">Pick a product</option>
                        {products.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Unit"
                    htmlFor="cp_unit"
                    error={form.errors.product_unit_id}
                >
                    <NativeSelect
                        id="cp_unit"
                        value={form.data.product_unit_id}
                        onChange={(e) =>
                            form.setData('product_unit_id', e.target.value)
                        }
                        disabled={!product}
                    >
                        {(product?.units ?? []).map((u) => (
                            <option key={u.id} value={u.is_base ? '' : u.id}>
                                {u.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Price"
                    htmlFor="cp_price"
                    error={form.errors.price}
                >
                    <Input
                        id="cp_price"
                        required
                        inputMode="decimal"
                        value={form.data.price}
                        onChange={(e) => form.setData('price', e.target.value)}
                    />
                </Field>
                <Field
                    label="From"
                    htmlFor="cp_from"
                    error={form.errors.starts_on}
                >
                    <Input
                        id="cp_from"
                        type="date"
                        value={form.data.starts_on}
                        onChange={(e) =>
                            form.setData('starts_on', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Until"
                    htmlFor="cp_until"
                    error={form.errors.ends_on}
                >
                    <Input
                        id="cp_until"
                        type="date"
                        value={form.data.ends_on}
                        onChange={(e) =>
                            form.setData('ends_on', e.target.value)
                        }
                    />
                </Field>
                <Button
                    type="submit"
                    variant="outline"
                    disabled={form.processing}
                >
                    Add
                </Button>
            </form>
        </Section>
    );
}
