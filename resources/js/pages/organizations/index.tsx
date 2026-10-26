import { Head, Link } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { SearchBox } from '@/components/yard/search-box';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import type { Organization, Paginated } from '@/types';

export default function Organizations({
    organizations,
    filters,
}: {
    organizations: Paginated<Organization>;
    filters: { search?: string };
}) {
    return (
        <>
            <Head title="Organizations" />
            <PageBody>
                <PageHeader
                    title="Organizations"
                    description="Buying accounts, with their pricing and credit."
                    actions={
                        <Button asChild>
                            <Link href={OrganizationController.create()}>
                                Add organization
                            </Link>
                        </Button>
                    }
                />
                <div className="mb-4">
                    <SearchBox
                        value={filters.search}
                        placeholder="Search name or account number"
                    />
                </div>
                <Table>
                    <thead>
                        <tr>
                            <Th>Account</Th>
                            <Th>Name</Th>
                            <Th>Price list</Th>
                            <Th numeric>Credit limit</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {organizations.data.length === 0 && (
                            <EmptyRow colSpan={4}>
                                No organizations match.
                            </EmptyRow>
                        )}
                        {organizations.data.map((org) => (
                            <tr key={org.id} className="hover:bg-accent/50">
                                <Td className="whitespace-nowrap text-muted-foreground">
                                    {org.account_number}
                                </Td>
                                <Td>
                                    <Link
                                        href={OrganizationController.show(
                                            org.id,
                                        )}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {org.name}
                                    </Link>
                                    {org.credit_hold && (
                                        <span className="ml-2 text-xs font-medium text-danger">
                                            On hold
                                        </span>
                                    )}
                                </Td>
                                <Td className="text-muted-foreground">
                                    {org.price_list?.name ?? 'List price'}
                                </Td>
                                <Td numeric>
                                    {org.credit_limit_cents > 0
                                        ? formatMoney(org.credit_limit_cents)
                                        : 'Prepaid only'}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
                <Pagination page={organizations} />
            </PageBody>
        </>
    );
}

Organizations.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
