import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    Lock,
    Unlock,
} from 'lucide-react';
import PayPeriodController from '@/actions/App/Http/Controllers/Time/PayPeriodController';
import TimesheetController from '@/actions/App/Http/Controllers/Time/TimesheetController';
import InputError from '@/components/input-error';
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
import type { PayPeriodInfo } from '@/types/time';

type Row = {
    id: number;
    name: string;
    job_title: string | null;
    yard: string | null;
    archived: boolean;
    regular_label: string;
    overtime_label: string;
    total_label: string;
    breaks_label: string;
    overtime: number;
    exceptions: number;
    exception_labels: string[];
    blocker: string | null;
    open: boolean;
    status: 'open' | 'submitted' | 'approved';
    status_label: string;
    approved_by: string | null;
    has_hours: boolean;
};

type Props = {
    period: PayPeriodInfo;
    rows: Row[];
    totals: {
        regular: string;
        overtime: string;
        people: number;
        approved: number;
        exceptions: number;
    };
    formats: { value: string; label: string }[];
    exports: {
        id: number;
        format: string;
        employees: number;
        hours: string;
        by: string | null;
        at: string | null;
        complete: boolean;
        note: string | null;
    }[];
    quickbooks: boolean;
};

/**
 * Timesheet review (time mockup 4): the pay period by person, exceptions
 * first, approval, then lock and export to payroll.
 */
export default function Timesheets({
    period,
    rows,
    totals,
    exports,
    quickbooks,
}: Props) {
    const { errors } = usePage().props;
    const waiting = totals.people - totals.approved;
    const open = period.status === 'open';

    return (
        <PageBody>
            <Head title="Timesheets" />
            <PageHeader
                eyebrow="Team"
                title="Timesheets"
                description={
                    <span className="flex items-center gap-2">
                        <Link
                            href={TimesheetController.index({
                                query: { date: period.prev_date },
                            })}
                            className="rounded p-1 hover:bg-secondary"
                            aria-label="Previous pay period"
                        >
                            <ChevronLeft className="size-4" />
                        </Link>
                        <span>
                            {period.label}
                            {period.is_current ? ' · this period' : ''}
                        </span>
                        {period.next_date && (
                            <Link
                                href={TimesheetController.index({
                                    query: { date: period.next_date },
                                })}
                                className="rounded p-1 hover:bg-secondary"
                                aria-label="Next pay period"
                            >
                                <ChevronRight className="size-4" />
                            </Link>
                        )}
                        <Chip
                            tone={
                                period.status === 'exported'
                                    ? 'success'
                                    : period.status === 'locked'
                                      ? 'info'
                                      : 'neutral'
                            }
                        >
                            {period.status_label}
                        </Chip>
                    </span>
                }
                actions={
                    open ? (
                        <>
                            <Button
                                variant="outline"
                                disabled={waiting === 0}
                                onClick={() =>
                                    router.post(
                                        TimesheetController.approveAll.url(
                                            period.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve all that are ready
                            </Button>
                            <Button
                                disabled={!period.has_ended}
                                title={
                                    period.has_ended
                                        ? undefined
                                        : 'A pay period can be locked once it has ended.'
                                }
                                onClick={() =>
                                    router.post(
                                        PayPeriodController.lock.url(period.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Lock className="size-4" /> Lock for payroll
                            </Button>
                        </>
                    ) : period.status === 'locked' ? (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    PayPeriodController.unlock.url(period.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Unlock className="size-4" /> Reopen
                        </Button>
                    ) : null
                }
            />

            <InputError message={errors.time as string | undefined} />

            <StatGrid>
                <Stat label="Regular" value={totals.regular} />
                <Stat
                    label="Overtime"
                    value={totals.overtime}
                    tone={totals.overtime !== '0h' ? 'warning' : undefined}
                />
                <Stat
                    label="Approved"
                    value={`${totals.approved} of ${totals.people}`}
                    tone={
                        totals.people > 0 && waiting === 0
                            ? 'success'
                            : undefined
                    }
                />
                <Stat
                    label="Need a look"
                    value={totals.exceptions}
                    tone={totals.exceptions > 0 ? 'warning' : undefined}
                />
            </StatGrid>

            <Section flush>
                <Table minWidth={820}>
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th numeric>Regular</Th>
                            <Th numeric>Overtime</Th>
                            <Th numeric>Total</Th>
                            <Th>Needs a look</Th>
                            <Th>Status</Th>
                            <Th />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyRow colSpan={7}>
                                Nobody tracks time yet.
                            </EmptyRow>
                        ) : (
                            rows.map((r) => (
                                <tr
                                    key={r.id}
                                    className={
                                        r.has_hours ? undefined : 'opacity-60'
                                    }
                                >
                                    <Td>
                                        <Link
                                            href={TimesheetController.show.url(
                                                r.id,
                                                {
                                                    query: {
                                                        period: period.id,
                                                    },
                                                },
                                            )}
                                            className="font-semibold hover:underline"
                                        >
                                            {r.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {[
                                                r.job_title,
                                                r.yard,
                                                r.archived
                                                    ? 'No longer here'
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </Td>
                                    <Td numeric>{r.regular_label}</Td>
                                    <Td numeric>
                                        <span
                                            className={
                                                r.overtime > 0
                                                    ? 'text-warning'
                                                    : undefined
                                            }
                                        >
                                            {r.overtime_label}
                                        </span>
                                    </Td>
                                    <Td numeric className="font-semibold">
                                        {r.total_label}
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            {r.open && (
                                                <Chip tone="info">
                                                    On the clock
                                                </Chip>
                                            )}
                                            {r.exception_labels.map((e) => (
                                                <Chip key={e} tone="warning">
                                                    {e}
                                                </Chip>
                                            ))}
                                        </div>
                                    </Td>
                                    <Td>
                                        <Chip
                                            tone={
                                                r.status === 'approved'
                                                    ? 'success'
                                                    : r.status === 'submitted'
                                                      ? 'info'
                                                      : 'neutral'
                                            }
                                        >
                                            {r.status_label}
                                        </Chip>
                                        {r.approved_by && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                by {r.approved_by}
                                            </div>
                                        )}
                                    </Td>
                                    <Td className="text-right">
                                        {open &&
                                            r.status !== 'approved' &&
                                            r.has_hours &&
                                            (r.blocker ? (
                                                <span className="text-xs text-muted-foreground">
                                                    {r.blocker}
                                                </span>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            TimesheetController.approve.url(
                                                                r.id,
                                                            ),
                                                            {
                                                                period_id:
                                                                    period.id,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Approve
                                                </Button>
                                            ))}
                                    </Td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </Section>

            <Section
                title="Payroll"
                description={
                    open
                        ? 'Approve everyone and lock the period once it ends; then send the hours to payroll.'
                        : 'Hours are the approved totals. An exported period never changes; late fixes go into the next one as hours adjustments.'
                }
            >
                {open ? (
                    <Notice>
                        {period.has_ended
                            ? waiting > 0
                                ? `${waiting} ${waiting === 1 ? 'timesheet needs' : 'timesheets need'} approval before this period can be locked.`
                                : 'Everyone is approved. Lock the period to export it.'
                            : 'This period is still running. Approve days as you go; lock it once it ends.'}
                    </Notice>
                ) : (
                    <div className="flex flex-wrap gap-2.5">
                        <Button variant="outline" asChild>
                            <a
                                href={PayPeriodController.download.url({
                                    period: period.id,
                                    format: 'summary',
                                })}
                            >
                                <Download className="size-4" /> Hours summary
                                (CSV)
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a
                                href={PayPeriodController.download.url({
                                    period: period.id,
                                    format: 'adp',
                                })}
                            >
                                <Download className="size-4" /> ADP import (CSV)
                            </a>
                        </Button>
                        {quickbooks && (
                            <Button
                                onClick={() =>
                                    router.post(
                                        PayPeriodController.quickbooks.url(
                                            period.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Send to QuickBooks
                            </Button>
                        )}
                    </div>
                )}
                <InputError message={errors.format as string | undefined} />

                {exports.length > 0 && (
                    <ul className="grid gap-1 text-sm">
                        {exports.map((x) => (
                            <li key={x.id} className="text-muted-foreground">
                                <span className="font-medium text-foreground">
                                    {x.format}
                                </span>{' '}
                                · {x.employees} people · {x.hours} · {x.by},{' '}
                                {x.at}
                                {!x.complete && (
                                    <span className="text-warning">
                                        {' '}
                                        · not finished
                                        {x.note ? `: ${x.note}` : ''}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </PageBody>
    );
}
