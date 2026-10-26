import { Head } from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import { CustomerForm } from '@/components/yard/customer-form';
import { PageBody, PageHeader } from '@/components/yard/page-header';

export default function CreateCustomer({
    organizations,
    organizationId,
}: {
    organizations: { id: number; name: string; account_number: string }[];
    organizationId: number | null;
}) {
    return (
        <>
            <Head title="New customer" />
            <PageBody>
                <PageHeader title="New customer" />
                <CustomerForm
                    organizations={organizations}
                    organizationId={organizationId}
                />
            </PageBody>
        </>
    );
}

CreateCustomer.layout = {
    breadcrumbs: [
        { title: 'Customers', href: CustomerController.index() },
        { title: 'New', href: CustomerController.create() },
    ],
};
