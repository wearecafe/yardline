import { Head, Link, router, useForm } from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import CustomerDocumentController from '@/actions/App/Http/Controllers/CustomerDocumentController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { Chip } from '@/components/yard/chip';
import { CustomerForm } from '@/components/yard/customer-form';
import { Field, NativeSelect } from '@/components/yard/field';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import type { Customer } from '@/types';

type Document = {
    id: number;
    kind: string;
    label: string;
    reference: string | null;
    expires_on: string | null;
    current: boolean;
    url: string;
};

/**
 * Licence and insurance on file. Rentals check these at checkout so the
 * counter doesn't have to ask every time.
 */
function Documents({
    customer,
    documents,
}: {
    customer: Customer;
    documents: Document[];
}) {
    const form = useForm<{
        kind: string;
        file: File | null;
        expires_on: string;
        reference: string;
    }>({
        kind: 'drivers_licence',
        file: null,
        expires_on: '',
        reference: '',
    });

    return (
        <Section title="Licence and insurance" className="max-w-3xl">
            {documents.length > 0 && (
                <ul className="mb-5 grid gap-3 text-sm">
                    {documents.map((doc) => (
                        <li
                            key={doc.id}
                            className="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div>
                                <a
                                    href={doc.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {doc.label}
                                </a>
                                {doc.reference && (
                                    <span className="figure ml-2 text-muted-foreground">
                                        {doc.reference}
                                    </span>
                                )}
                                <div className="text-muted-foreground">
                                    {doc.expires_on
                                        ? `Expires ${formatDate(doc.expires_on)}`
                                        : 'No expiry'}
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <Chip tone={doc.current ? 'success' : 'danger'}>
                                    {doc.current ? 'Current' : 'Expired'}
                                </Chip>
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(
                                            CustomerDocumentController.destroy.url(
                                                {
                                                    customer: customer.id,
                                                    document: doc.id,
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
                    ))}
                </ul>
            )}
            <form
                className="grid gap-4 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(
                        CustomerDocumentController.store.url(customer.id),
                        {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        },
                    );
                }}
            >
                <Field
                    label="Document"
                    htmlFor="doc_kind"
                    error={form.errors.kind}
                >
                    <NativeSelect
                        id="doc_kind"
                        value={form.data.kind}
                        onChange={(e) => form.setData('kind', e.target.value)}
                    >
                        <option value="drivers_licence">
                            Driver's licence
                        </option>
                        <option value="insurance_certificate">
                            Insurance certificate
                        </option>
                    </NativeSelect>
                </Field>
                <Field
                    label="Photo or PDF"
                    htmlFor="doc_file"
                    error={form.errors.file}
                >
                    <Input
                        id="doc_file"
                        type="file"
                        accept="image/*,application/pdf"
                        required
                        onChange={(e) =>
                            form.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                </Field>
                <Field
                    label="Number"
                    htmlFor="doc_ref"
                    error={form.errors.reference}
                    hint="Last few digits are enough."
                >
                    <Input
                        id="doc_ref"
                        value={form.data.reference}
                        onChange={(e) =>
                            form.setData('reference', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Expires"
                    htmlFor="doc_expires"
                    error={form.errors.expires_on}
                >
                    <Input
                        id="doc_expires"
                        type="date"
                        value={form.data.expires_on}
                        onChange={(e) =>
                            form.setData('expires_on', e.target.value)
                        }
                    />
                </Field>
                <div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing}
                    >
                        Add to file
                    </Button>
                </div>
            </form>
        </Section>
    );
}

export default function EditCustomer({
    customer,
    organizations,
    memberships,
    documents,
}: {
    customer: Customer;
    organizations: { id: number; name: string; account_number: string }[];
    memberships: {
        id: number;
        name: string;
        role: string;
        can_charge_account: boolean;
    }[];
    documents: Document[];
}) {
    return (
        <>
            <Head title={customer.full_name} />
            <PageBody>
                <PageHeader title={customer.full_name} />
                <CustomerForm
                    key={customer.id}
                    customer={customer}
                    organizations={organizations}
                />
                {memberships.length > 0 && (
                    <Section title="Buys for" className="max-w-3xl">
                        <ul className="grid gap-2 text-sm">
                            {memberships.map((m) => (
                                <li
                                    key={m.id}
                                    className="flex flex-wrap items-center justify-between gap-3"
                                >
                                    <Link
                                        href={OrganizationController.show(m.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {m.name}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {m.role} ·{' '}
                                        {m.can_charge_account
                                            ? 'can charge to the account'
                                            : 'pays by card'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-muted-foreground">
                            Roles and charge rights are set on each
                            organization's page.
                        </p>
                    </Section>
                )}
                <Documents customer={customer} documents={documents} />
            </PageBody>
        </>
    );
}

EditCustomer.layout = {
    breadcrumbs: [{ title: 'Customers', href: CustomerController.index() }],
};
