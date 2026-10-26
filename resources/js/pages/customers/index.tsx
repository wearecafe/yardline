import { Head, Link } from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { PageBody, PageHeader } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { SearchBox } from '@/components/yard/search-box';
import { Button } from '@/components/ui/button';
import type { Customer, Paginated } from '@/types';

export default function Customers({
    customers,
    filters,
}: {
    customers: Paginated<Customer>;
    filters: { search?: string };
}) {
    return (
        <>
            <Head title="Customers" />
            <PageBody>
                <PageHeader
                    title="Customers"
                    description="People who buy: organization contacts and walk-ins."
                    actions={
                        <Button asChild>
                            <Link href={CustomerController.create()}>
                                Add customer
                            </Link>
                        </Button>
                    }
                />
                <div className="mb-4">
                    <SearchBox
                        value={filters.search}
                        placeholder="Search name, phone or email"
                    />
                </div>
                <Table>
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Organization</Th>
                            <Th>Phone</Th>
                            <Th>Email</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {customers.data.length === 0 && (
                            <EmptyRow colSpan={4}>No customers match.</EmptyRow>
                        )}
                        {customers.data.map((c) => (
                            <tr key={c.id} className="hover:bg-accent/50">
                                <Td>
                                    <Link
                                        href={CustomerController.edit(c.id)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {c.full_name}
                                    </Link>
                                </Td>
                                <Td>
                                    {c.organization ? (
                                        <Link
                                            href={OrganizationController.show(
                                                c.organization.id,
                                            )}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {c.organization.name}
                                        </Link>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Walk-in
                                        </span>
                                    )}
                                </Td>
                                <Td className="whitespace-nowrap">{c.phone}</Td>
                                <Td className="text-muted-foreground">
                                    {c.email}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
                <Pagination page={customers} />
            </PageBody>
        </>
    );
}

Customers.layout = {
    breadcrumbs: [{ title: 'Customers', href: CustomerController.index() }],
};
