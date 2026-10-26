import { Head } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { OrganizationForm } from '@/components/yard/organization-form';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import type { Option, Organization } from '@/types';

type Props = {
    organization: Organization;
    priceLists: { id: number; name: string }[];
    paymentTerms: Option[];
    canManageCredit: boolean;
};

export default function EditOrganization(props: Props) {
    return (
        <>
            <Head title={`Edit ${props.organization.name}`} />
            <PageBody>
                <PageHeader
                    title={props.organization.name}
                    description={props.organization.account_number}
                />
                <OrganizationForm key={props.organization.id} {...props} />
            </PageBody>
        </>
    );
}

EditOrganization.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
