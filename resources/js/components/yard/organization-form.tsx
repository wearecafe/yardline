import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import { Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { centsToInput } from '@/lib/format';
import type { Option, Organization } from '@/types';

export function OrganizationForm({
    organization,
    priceLists,
    paymentTerms,
    canManageCredit,
}: {
    organization?: Organization;
    priceLists: { id: number; name: string }[];
    paymentTerms: Option[];
    canManageCredit: boolean;
}) {
    const form = useForm({
        name: organization?.name ?? '',
        account_number: organization?.account_number ?? '',
        email: organization?.email ?? '',
        phone: organization?.phone ?? '',
        address_line1: organization?.address_line1 ?? '',
        address_line2: organization?.address_line2 ?? '',
        city: organization?.city ?? '',
        region: organization?.region ?? 'ON',
        postal_code: organization?.postal_code ?? '',
        country: organization?.country ?? 'CA',
        price_list_id: organization?.price_list_id
            ? String(organization.price_list_id)
            : '',
        credit_limit: centsToInput(organization?.credit_limit_cents ?? 0),
        payment_terms: organization?.payment_terms ?? 'due_on_receipt',
        credit_hold: organization?.credit_hold ?? false,
        tax_exempt: organization?.tax_exempt ?? false,
        tax_exempt_number: organization?.tax_exempt_number ?? '',
        notes: organization?.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (organization) {
            form.put(OrganizationController.update.url(organization.id));
        } else {
            form.post(OrganizationController.store.url());
        }
    }

    const text = (key: keyof typeof form.data) => ({
        id: key,
        value: form.data[key] as string,
        onChange: (e: { target: { value: string } }) =>
            form.setData(key, e.target.value),
    });

    return (
        <form onSubmit={submit} className="grid max-w-3xl gap-8">
            <Section title="Account">
                <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
                    <Field label="Name" htmlFor="name" error={form.errors.name}>
                        <Input
                            {...text('name')}
                            required
                            autoFocus={!organization}
                        />
                    </Field>
                    <Field
                        label="Account number"
                        htmlFor="account_number"
                        error={form.errors.account_number}
                    >
                        <Input {...text('account_number')} required />
                    </Field>
                    <Field
                        label="Email"
                        htmlFor="email"
                        error={form.errors.email}
                    >
                        <Input type="email" {...text('email')} />
                    </Field>
                    <Field
                        label="Phone"
                        htmlFor="phone"
                        error={form.errors.phone}
                    >
                        <Input type="tel" {...text('phone')} />
                    </Field>
                </div>
            </Section>

            <Section title="Billing address">
                <div className="grid gap-4 sm:grid-cols-6">
                    <Field
                        label="Street"
                        htmlFor="address_line1"
                        error={form.errors.address_line1}
                        className="sm:col-span-6"
                    >
                        <Input
                            {...text('address_line1')}
                            autoComplete="address-line1"
                        />
                    </Field>
                    <Field
                        label="Unit or suite"
                        htmlFor="address_line2"
                        error={form.errors.address_line2}
                        className="sm:col-span-6"
                    >
                        <Input
                            {...text('address_line2')}
                            autoComplete="address-line2"
                        />
                    </Field>
                    <Field
                        label="City"
                        htmlFor="city"
                        error={form.errors.city}
                        className="sm:col-span-3"
                    >
                        <Input {...text('city')} />
                    </Field>
                    <Field
                        label="Province"
                        htmlFor="region"
                        error={form.errors.region}
                        className="sm:col-span-1"
                    >
                        <Input {...text('region')} />
                    </Field>
                    <Field
                        label="Postal code"
                        htmlFor="postal_code"
                        error={form.errors.postal_code}
                        className="sm:col-span-2"
                    >
                        <Input {...text('postal_code')} />
                    </Field>
                </div>
            </Section>

            <Section title="Pricing and credit">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        label="Price list"
                        htmlFor="price_list_id"
                        error={form.errors.price_list_id}
                        hint="Blank means list price."
                    >
                        <NativeSelect
                            id="price_list_id"
                            value={form.data.price_list_id}
                            onChange={(e) =>
                                form.setData('price_list_id', e.target.value)
                            }
                        >
                            <option value="">List price</option>
                            {priceLists.map((list) => (
                                <option key={list.id} value={list.id}>
                                    {list.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        label="Credit limit"
                        htmlFor="credit_limit"
                        error={form.errors.credit_limit}
                        hint={
                            canManageCredit
                                ? '0 means every order is prepaid.'
                                : 'Only an owner or admin can change credit.'
                        }
                    >
                        <Input
                            {...text('credit_limit')}
                            inputMode="decimal"
                            disabled={!canManageCredit}
                        />
                    </Field>
                    <Field
                        label="Terms"
                        htmlFor="payment_terms"
                        error={form.errors.payment_terms}
                    >
                        <NativeSelect
                            id="payment_terms"
                            value={form.data.payment_terms}
                            disabled={!canManageCredit}
                            onChange={(e) =>
                                form.setData('payment_terms', e.target.value)
                            }
                        >
                            {paymentTerms.map((terms) => (
                                <option key={terms.value} value={terms.value}>
                                    {terms.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                </div>
                <div className="mt-4 grid gap-4">
                    <Checkbox
                        id="credit_hold"
                        label="Credit hold"
                        hint="New orders must be paid up front until the hold is lifted."
                        checked={form.data.credit_hold}
                        disabled={!canManageCredit}
                        onChange={(checked) =>
                            form.setData('credit_hold', checked)
                        }
                    />
                    <Checkbox
                        id="tax_exempt"
                        label="Tax exempt"
                        checked={form.data.tax_exempt}
                        onChange={(checked) =>
                            form.setData('tax_exempt', checked)
                        }
                    />
                    {form.data.tax_exempt && (
                        <Field
                            label="Exemption number"
                            htmlFor="tax_exempt_number"
                            error={form.errors.tax_exempt_number}
                            className="max-w-xs"
                        >
                            <Input {...text('tax_exempt_number')} />
                        </Field>
                    )}
                </div>
            </Section>

            <Section title="Notes">
                <textarea
                    id="notes"
                    aria-label="Notes"
                    rows={3}
                    className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </Section>

            <div>
                <Button type="submit" disabled={form.processing}>
                    {organization ? 'Save organization' : 'Add organization'}
                </Button>
            </div>
        </form>
    );
}
