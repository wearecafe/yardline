import { Head, Link, router } from '@inertiajs/react';
import { MonitorSmartphone } from 'lucide-react';
import { useEffect, useState } from 'react';
import EmployeeController from '@/actions/App/Http/Controllers/Time/EmployeeController';
import KioskController from '@/actions/App/Http/Controllers/Time/KioskController';
import TimesheetController from '@/actions/App/Http/Controllers/Time/TimesheetController';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import {
    Notice,
    PageBody,
    PageHeader,
    Section,
    Stat,
    StatGrid,
} from '@/components/yard/page-header';
import { ToggleButtons } from '@/components/yard/toggle-buttons';
import type { ClockStateValue } from '@/types/time';

type Row = {
    id: number;
    name: string;
    job_title: string | null;
    yard_id: number | null;
    state: ClockStateValue;
    state_label: string;
    since_label: string | null;
    last_label: string | null;
    where: string | null;
    away: boolean;
    truck: string | null;
    today_minutes: number;
    today_label: string;
    week_minutes: number;
    week_label: string;
    overtime: boolean;
    near_overtime: boolean;
    missed_out: string | null;
    exceptions: string[];
};

type Props = {
    rows: Row[];
    yards: { id: number; name: string }[];
    today: string;
    overtime_after: number;
    counts: {
        working: number;
        on_break: number;
        out: number;
        near_overtime: number;
    };
    unset: number;
};

const stateOrder: Record<ClockStateValue, number> = {
    working: 0,
    on_break: 1,
    out: 2,
};

/**
 * Who's in (time mockup 3): everyone on the clock right now by yard,
 * with today's and this week's hours and early overtime warnings.
 */
export default function WhosIn({
    rows,
    yards,
    today,
    overtime_after,
    counts,
    unset,
}: Props) {
    const [yard, setYard] = useState('all');

    // The board stays up on the office screen; keep it current.
    useEffect(() => {
        const id = window.setInterval(
            () => router.reload({ only: ['rows', 'counts'] }),
            60_000,
        );

        return () => window.clearInterval(id);
    }, []);

    const shown = rows
        .filter((r) => yard === 'all' || String(r.yard_id) === yard)
        .sort(
            (a, b) =>
                stateOrder[a.state] - stateOrder[b.state] ||
                a.name.localeCompare(b.name),
        );

    return (
        <PageBody>
            <Head title="Who’s in" />
            <PageHeader
                eyebrow="Team"
                title="Who’s in"
                description={today}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href={TimesheetController.index()}>
                                Timesheets
                            </Link>
                        </Button>
                        <Button asChild>
                            <a href={KioskController.show.url()}>
                                <MonitorSmartphone className="size-4" />
                                Open the time clock
                            </a>
                        </Button>
                    </>
                }
            />

            <StatGrid>
                <Stat label="Working" value={counts.working} tone="success" />
                <Stat label="On break" value={counts.on_break} />
                <Stat label="Clocked out" value={counts.out} />
                <Stat
                    label={`Near or over ${overtime_after}h this week`}
                    value={counts.near_overtime}
                    tone={counts.near_overtime > 0 ? 'warning' : undefined}
                />
            </StatGrid>

            {unset > 0 && (
                <Notice tone="warning">
                    {unset === 1
                        ? '1 person who only uses the time clock has no PIN yet.'
                        : `${unset} people who only use the time clock have no PIN yet.`}{' '}
                    <Link
                        href={EmployeeController.index()}
                        className="font-semibold underline"
                    >
                        Set PINs
                    </Link>
                </Notice>
            )}

            {yards.length > 1 && (
                <ToggleButtons
                    label="Yard"
                    size="sm"
                    value={yard}
                    onChange={setYard}
                    options={[
                        { value: 'all', label: 'All yards' },
                        ...yards.map((y) => ({
                            value: String(y.id),
                            label: y.name,
                        })),
                    ]}
                />
            )}

            <Section flush>
                <Table minWidth={760}>
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Status</Th>
                            <Th>Where</Th>
                            <Th numeric>Today</Th>
                            <Th numeric>This week</Th>
                            <Th>Needs a look</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {shown.length === 0 ? (
                            <EmptyRow colSpan={6}>
                                Nobody is set up to clock in yet.{' '}
                                <Link
                                    href={EmployeeController.index()}
                                    className="font-semibold underline"
                                >
                                    Add people
                                </Link>
                            </EmptyRow>
                        ) : (
                            shown.map((r) => (
                                <tr key={r.id}>
                                    <Td>
                                        <Link
                                            href={TimesheetController.show.url(
                                                r.id,
                                            )}
                                            className="font-semibold hover:underline"
                                        >
                                            {r.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {[r.job_title, r.truck]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </Td>
                                    <Td>
                                        <Chip
                                            tone={
                                                r.state === 'working'
                                                    ? 'success'
                                                    : r.state === 'on_break'
                                                      ? 'warning'
                                                      : 'neutral'
                                            }
                                        >
                                            {r.state_label}
                                            {r.since_label
                                                ? ` · ${r.since_label}`
                                                : ''}
                                        </Chip>
                                        {r.state === 'out' && r.last_label && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Last: {r.last_label}
                                            </div>
                                        )}
                                    </Td>
                                    <Td
                                        className={
                                            r.away ? 'text-warning' : undefined
                                        }
                                    >
                                        {r.where ?? '—'}
                                    </Td>
                                    <Td numeric>{r.today_label}</Td>
                                    <Td numeric>
                                        <span
                                            className={
                                                r.overtime
                                                    ? 'font-semibold text-danger'
                                                    : r.near_overtime
                                                      ? 'font-semibold text-warning'
                                                      : undefined
                                            }
                                        >
                                            {r.week_label}
                                        </span>
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            {r.overtime && (
                                                <Chip tone="danger">
                                                    Overtime
                                                </Chip>
                                            )}
                                            {r.near_overtime && (
                                                <Chip tone="warning">
                                                    Near overtime
                                                </Chip>
                                            )}
                                            {r.missed_out && (
                                                <Chip tone="warning">
                                                    No clock-out {r.missed_out}
                                                </Chip>
                                            )}
                                            {r.exceptions.map((e) => (
                                                <Chip key={e} tone="warning">
                                                    {e}
                                                </Chip>
                                            ))}
                                        </div>
                                    </Td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </Section>
        </PageBody>
    );
}
