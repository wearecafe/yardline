import { Head, Link } from '@inertiajs/react';
import FleetController from '@/actions/App/Http/Controllers/Rentals/FleetController';
import RentalController from '@/actions/App/Http/Controllers/Rentals/RentalController';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import {
    PageBody,
    PageHeader,
    Section,
    Stat,
    StatGrid,
} from '@/components/yard/page-header';
import { UnitStatusChip } from '@/components/yard/rental-status';
import { Button } from '@/components/ui/button';
import { formatMoney, formatQuantity } from '@/lib/format';
import { cn } from '@/lib/utils';

type Unit = {
    id: number;
    name: string;
    category: string | null;
    serial: string;
    status: string;
    with: string | null;
    rental_id: number | null;
    hours: string | null;
    service: string | null;
    service_soon: boolean;
    day_rate_cents: number;
    location: string;
};

type Props = {
    units: Unit[];
    stats: {
        total: number;
        on_rent: number;
        in_shop: number;
        utilization: number;
        due_today: number;
        overdue: number;
        service_soon: number;
    };
};

export default function Fleet({ units, stats }: Props) {
    return (
        <>
            <Head title="Fleet" />
            <PageBody>
                <PageHeader
                    title="Fleet"
                    description={`${stats.total} units · ${stats.on_rent} out on rent · ${stats.in_shop} in the shop`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={FleetController.create()}>
                                    Add unit
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={RentalController.create()}>
                                    New rental
                                </Link>
                            </Button>
                        </>
                    }
                />

                <StatGrid>
                    <Stat
                        label="Utilization, last 30 days"
                        value={`${stats.utilization}%`}
                    />
                    <Stat label="Due back today" value={stats.due_today} />
                    <Stat
                        label="Overdue"
                        value={stats.overdue}
                        tone={stats.overdue > 0 ? 'danger' : undefined}
                    />
                    <Stat
                        label="Service due within 10 hrs"
                        value={stats.service_soon}
                        tone={stats.service_soon > 0 ? 'warning' : undefined}
                    />
                </StatGrid>

                <Section flush>
                    <Table minWidth={900}>
                        <thead>
                            <tr>
                                <Th>Unit</Th>
                                <Th>Serial</Th>
                                <Th>Status</Th>
                                <Th>With / due back</Th>
                                <Th numeric>Hours</Th>
                                <Th>Next service</Th>
                                <Th numeric>Day rate</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {units.length === 0 && (
                                <EmptyRow colSpan={7}>
                                    No units yet. Add your first piece of
                                    equipment with Add unit.
                                </EmptyRow>
                            )}
                            {units.map((unit) => (
                                <tr
                                    key={unit.id}
                                    className="hover:bg-accent/60"
                                >
                                    <Td>
                                        <Link
                                            href={FleetController.show(unit.id)}
                                            className="font-semibold text-foreground underline-offset-4 hover:underline"
                                        >
                                            {unit.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {unit.category}
                                        </div>
                                    </Td>
                                    <Td className="figure text-muted-foreground">
                                        {unit.serial}
                                    </Td>
                                    <Td>
                                        <UnitStatusChip status={unit.status} />
                                    </Td>
                                    <Td className="text-subtle">
                                        {unit.rental_id ? (
                                            <Link
                                                href={RentalController.show(
                                                    unit.rental_id,
                                                )}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {unit.with}
                                            </Link>
                                        ) : (
                                            (unit.with ?? '—')
                                        )}
                                    </Td>
                                    <Td numeric>
                                        {unit.hours
                                            ? formatQuantity(unit.hours)
                                            : '—'}
                                    </Td>
                                    <Td
                                        className={cn(
                                            'text-subtle',
                                            unit.service_soon &&
                                                'font-medium text-[#a16207] dark:text-warning',
                                        )}
                                    >
                                        {unit.service ?? '—'}
                                    </Td>
                                    <Td numeric>
                                        {formatMoney(unit.day_rate_cents)}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Section>
            </PageBody>
        </>
    );
}

Fleet.layout = {
    breadcrumbs: [{ title: 'Fleet', href: FleetController.index() }],
};
