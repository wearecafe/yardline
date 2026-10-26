import { Head } from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import { CustomerForm } from '@/components/yard/customer-form';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import type { Customer } from '@/types';

export default function EditCustomer({
    customer,
    organizations,
}: {
    customer: Customer;
    organizations: { id: number; name: string; account_number: string }[];
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
            </PageBody>
        </>
    );
}

EditCustomer.layout = {
    breadcrumbs: [{ title: 'Customers', href: CustomerController.index() }],
};
