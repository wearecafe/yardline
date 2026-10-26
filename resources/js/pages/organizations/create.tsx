import { Head } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { OrganizationForm } from '@/components/yard/organization-form';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import type { Option } from '@/types';

type Props = {
    priceLists: { id: number; name: string }[];
    paymentTerms: Option[];
    canManageCredit: boolean;
};

export default function CreateOrganization(props: Props) {
    return (
        <>
            <Head title="New organization" />
            <PageBody>
                <PageHeader
                    title="New organization"
                    description="A contractor, landscaper or builder who buys from you."
                />
                <OrganizationForm {...props} />
            </PageBody>
        </>
    );
}

CreateOrganization.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
        { title: 'New', href: OrganizationController.create() },
    ],
};
