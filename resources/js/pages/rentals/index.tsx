import { Head, Link, router } from '@inertiajs/react';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { Pagination } from '@/components/yard/pagination';
import { RentalStatusChip } from '@/components/yard/rental-status';
import { SearchBox } from '@/components/yard/search-box';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { Paginated, RentalSummary } from '@/types';

type Props = {
    rentals: Paginated<RentalSummary>;
    filters: { status?: string; search?: string };
};

const statuses = [
    { value: '', label: 'All' },
    { value: 'quote', label: 'Quotes' },
    { value: 'reserved', label: 'Reserved' },
    { value: 'out', label: 'On rent' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'returned', label: 'Returned' },
];

export default function Rentals({ rentals, filters }: Props) {
    return (
        <>
            <Head title="Rentals and contracts" />
            <PageBody>
                <PageHeader
                    title="Rentals and contracts"
                    actions={
                        <Button asChild>
                            <Link href={RentalController.create()}>
                                New rental
                            </Link>
                        </Button>
                    }
                />
                <div className="flex flex-wrap items-center gap-3">
                    <ToggleButtons
                        label="Status"
                        value={filters.status ?? ''}
                        onChange={(status) =>
                            router.get(
                                RentalController.index.url(),
                                { ...filters, status: status || undefined },
                                { preserveState: true, replace: true },
                            )
                        }
                        options={statuses}
                    />
                    <SearchBox
                        value={filters.search}
                        placeholder="Number, company or customer"
                        extra={{ status: filters.status }}
                    />
                </div>
                <Section flush>
                    <Table minWidth={860}>
                        <thead>
                            <tr>
                                <Th>Rental</Th>
                                <Th>Customer</Th>
                                <Th>Equipment</Th>
                                <Th>Out</Th>
                                <Th>Back</Th>
                                <Th>Status</Th>
                                <Th numeric>Total</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rentals.data.length === 0 && (
                                <EmptyRow colSpan={7}>
                                    No rentals match.
                                </EmptyRow>
                            )}
                            {rentals.data.map((r) => (
                                <tr key={r.id} className="hover:bg-accent/60">
                                    <Td>
                                        <Link
                                            href={RentalController.show(r.id)}
                                            className="figure font-medium underline-offset-4 hover:underline"
                                        >
                                            {r.number}
                                        </Link>
                                        {r.source === 'online' && (
                                            <div className="text-xs text-muted-foreground">
                                                Booked online
                                            </div>
                                        )}
                                    </Td>
                                    <Td className="max-w-48 truncate">
                                        {r.customer}
                                    </Td>
                                    <Td className="max-w-64 truncate text-subtle">
                                        {r.equipment}
                                    </Td>
                                    <Td className="whitespace-nowrap text-subtle">
                                        {formatDateTime(r.starts_at)}
                                    </Td>
                                    <Td className="whitespace-nowrap text-subtle">
                                        {formatDateTime(r.ends_at)}
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <RentalStatusChip
                                                status={r.status}
                                            />
                                            {!r.signed &&
                                                ['quote', 'reserved'].includes(
                                                    r.status,
                                                ) && (
                                                    <span className="text-xs text-muted-foreground">
                                                        Unsigned
                                                    </span>
                                                )}
                                        </div>
                                    </Td>
                                    <Td numeric>
                                        {formatMoney(r.total_cents)}
                                        {r.balance_cents > 0 &&
                                            r.status !== 'quote' && (
                                                <div className="text-xs text-[#a16207]">
                                                    {formatMoney(
                                                        r.balance_cents,
                                                    )}{' '}
                                                    owing
                                                </div>
                                            )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Section>
                <Pagination page={rentals} />
            </PageBody>
        </>
    );
}

Rentals.layout = {
    breadcrumbs: [{ title: 'Rentals', href: RentalController.index() }],
};
