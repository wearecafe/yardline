import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import PunchCorrectionController from '@/actions/App/Http/Controllers/Time/PunchCorrectionController';
import TimesheetController from '@/actions/App/Http/Controllers/Time/TimesheetController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/yard/chip';
import { Checkbox, Field, NativeSelect } from '@/components/yard/field';
import {
    Notice,
    PageBody,
    PageHeader,
    Section,
    Stat,
    StatGrid,
} from '@/components/yard/page-header';
import { TimeDays } from '@/components/yard/time-days';
import type {
    Adjustment,
    PayPeriodInfo,
    Punch,
    TimeDay,
    TimesheetInfo,
    TimeTotals,
} from '@/types/time';

type Props = {
    employee: {
        id: number;
        name: string;
        role: string | null;
        yard: string | null;
        pay_type: string;
        earns_overtime: boolean;
        payroll_id: string | null;
    };
    period: PayPeriodInfo;
    timesheet: TimesheetInfo;
    punch_types: { value: string; label: string }[];
    days: TimeDay[];
    totals: TimeTotals;
};

/** Edit or remove one punch, with a reason. */
function PunchActions({ punch }: { punch: Punch }) {
    const [mode, setMode] = useState<'idle' | 'edit' | 'remove'>('idle');
    const form = useForm({ at: punch.local, reason: '' });

    if (mode === 'idle') {
        return (
            <span className="ml-auto flex gap-2 text-xs">
                <button
                    type="button"
                    className="text-info hover:underline"
                    onClick={() => setMode('edit')}
                >
                    Change
                </button>
                <button
                    type="button"
                    className="text-danger hover:underline"
                    onClick={() => setMode('remove')}
                >
                    Remove
                </button>
            </span>
        );
    }

    return (
        <form
            className="mt-1 grid w-full gap-2 rounded-lg bg-secondary/60 p-3 sm:grid-cols-[auto_1fr_auto] sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                const options = {
                    preserveScroll: true,
                    onSuccess: () => setMode('idle'),
                };

                if (mode === 'edit') {
                    form.put(
                        PunchCorrectionController.update.url(punch.id),
                        options,
                    );
                } else {
                    form.delete(
                        PunchCorrectionController.destroy.url(punch.id),
                        options,
                    );
                }
            }}
        >
            {mode === 'edit' && (
                <Field
                    label={`${punch.label} at`}
                    htmlFor={`at-${punch.id}`}
                    error={form.errors.at}
                >
                    <Input
                        id={`at-${punch.id}`}
                        type="datetime-local"
                        value={form.data.at}
                        onChange={(e) => form.setData('at', e.target.value)}
                    />
                </Field>
            )}
            <Field
                label={
                    mode === 'edit'
                        ? 'Reason'
                        : `Why remove ${punch.label.toLowerCase()} ${punch.time}?`
                }
                htmlFor={`reason-${punch.id}`}
                error={form.errors.reason}
                className={mode === 'remove' ? 'sm:col-span-2' : undefined}
            >
                <Input
                    id={`reason-${punch.id}`}
                    value={form.data.reason}
                    placeholder="e.g. Forgot to clock out; left at 5:00"
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </Field>
            <div className="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    variant={mode === 'remove' ? 'destructive' : 'default'}
                    disabled={form.processing || form.data.reason.trim() === ''}
                >
                    {mode === 'edit' ? 'Save' : 'Remove'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setMode('idle')}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function DisputeActions({ adjustment }: { adjustment: Adjustment }) {
    if (!adjustment.open_dispute) {
        return null;
    }

    return (
        <Button
            size="sm"
            variant="outline"
            className="mt-2"
            onClick={() =>
                router.post(
                    PunchCorrectionController.resolve.url(adjustment.id),
                    {},
                    { preserveScroll: true },
                )
            }
        >
            Mark sorted out
        </Button>
    );
}

/** Add a missed punch or hours to a day. */
function DayFix({
    day,
    employeeId,
    punchTypes,
    earnsOvertime,
}: {
    day: TimeDay;
    employeeId: number;
    punchTypes: { value: string; label: string }[];
    earnsOvertime: boolean;
}) {
    const [mode, setMode] = useState<'idle' | 'punch' | 'hours'>('idle');
    const punch = useForm({
        type: 'out',
        at: `${day.date}T17:00`,
        reason: '',
    });
    const hours = useForm({
        date: day.date,
        hours: '',
        overtime: false,
        reason: '',
    });

    if (mode === 'idle') {
        return (
            <div className="mt-3 flex gap-3 text-sm">
                <button
                    type="button"
                    className="font-medium text-info hover:underline"
                    onClick={() => setMode('punch')}
                >
                    Add a missed punch
                </button>
                <button
                    type="button"
                    className="font-medium text-info hover:underline"
                    onClick={() => setMode('hours')}
                >
                    Adjust hours
                </button>
            </div>
        );
    }

    if (mode === 'punch') {
        return (
            <form
                className="mt-3 grid gap-3 rounded-lg bg-secondary/60 p-3 sm:grid-cols-3 sm:items-end"
                onSubmit={(e) => {
                    e.preventDefault();
                    punch.post(
                        PunchCorrectionController.store.url(employeeId),
                        {
                            preserveScroll: true,
                            onSuccess: () => setMode('idle'),
                        },
                    );
                }}
            >
                <Field
                    label="Punch"
                    htmlFor={`type-${day.date}`}
                    error={punch.errors.type}
                >
                    <NativeSelect
                        id={`type-${day.date}`}
                        value={punch.data.type}
                        onChange={(e) => punch.setData('type', e.target.value)}
                    >
                        {punchTypes.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="At"
                    htmlFor={`add-at-${day.date}`}
                    error={punch.errors.at}
                >
                    <Input
                        id={`add-at-${day.date}`}
                        type="datetime-local"
                        value={punch.data.at}
                        onChange={(e) => punch.setData('at', e.target.value)}
                    />
                </Field>
                <Field
                    label="Reason"
                    htmlFor={`add-reason-${day.date}`}
                    error={punch.errors.reason}
                >
                    <Input
                        id={`add-reason-${day.date}`}
                        value={punch.data.reason}
                        placeholder="e.g. Forgot to clock out"
                        onChange={(e) =>
                            punch.setData('reason', e.target.value)
                        }
                    />
                </Field>
                <div className="flex gap-2 sm:col-span-3">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={
                            punch.processing || punch.data.reason.trim() === ''
                        }
                    >
                        Add punch
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setMode('idle')}
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        );
    }

    return (
        <form
            className="mt-3 grid gap-3 rounded-lg bg-secondary/60 p-3 sm:grid-cols-3 sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                hours.post(PunchCorrectionController.hours.url(employeeId), {
                    preserveScroll: true,
                    onSuccess: () => setMode('idle'),
                });
            }}
        >
            <Field
                label="Hours (negative takes time off)"
                htmlFor={`hours-${day.date}`}
                error={hours.errors.hours}
            >
                <Input
                    id={`hours-${day.date}`}
                    inputMode="decimal"
                    placeholder="e.g. 1.5"
                    value={hours.data.hours}
                    onChange={(e) => hours.setData('hours', e.target.value)}
                />
            </Field>
            <Field
                label="Reason"
                htmlFor={`hours-reason-${day.date}`}
                error={hours.errors.reason}
                className="sm:col-span-2"
            >
                <Input
                    id={`hours-reason-${day.date}`}
                    value={hours.data.reason}
                    placeholder="e.g. Training at the Stoney Creek yard"
                    onChange={(e) => hours.setData('reason', e.target.value)}
                />
            </Field>
            {earnsOvertime && (
                <Checkbox
                    id={`hours-ot-${day.date}`}
                    label="Count as overtime"
                    checked={hours.data.overtime}
                    onChange={(v) => hours.setData('overtime', v)}
                />
            )}
            <div className="flex gap-2 sm:col-span-3">
                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        hours.processing ||
                        hours.data.reason.trim() === '' ||
                        hours.data.hours === ''
                    }
                >
                    Save adjustment
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setMode('idle')}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * One person's timesheet for a pay period: every day's punches, fixes
 * with a reason, disputes, and approval.
 */
export default function EmployeeTimesheet({
    employee,
    period,
    timesheet,
    punch_types,
    days,
    totals,
}: Props) {
    const { errors } = usePage().props;
    const editable = period.status === 'open';
    const [showEmpty, setShowEmpty] = useState(false);

    return (
        <PageBody className="max-w-4xl">
            <Head title={`${employee.name} · Timesheet`} />
            <PageHeader
                eyebrow={
                    <Link
                        href={TimesheetController.index({
                            query: { period: period.id },
                        })}
                        className="hover:underline"
                    >
                        Timesheets
                    </Link>
                }
                title={employee.name}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        <Link
                            href={TimesheetController.show.url(employee.id, {
                                query: { date: period.prev_date },
                            })}
                            className="rounded p-1 hover:bg-secondary"
                            aria-label="Previous pay period"
                        >
                            <ChevronLeft className="size-4" />
                        </Link>
                        {period.label}
                        {period.next_date && (
                            <Link
                                href={TimesheetController.show.url(
                                    employee.id,
                                    { query: { date: period.next_date } },
                                )}
                                className="rounded p-1 hover:bg-secondary"
                                aria-label="Next pay period"
                            >
                                <ChevronRight className="size-4" />
                            </Link>
                        )}
                        <span className="text-muted-foreground">
                            ·{' '}
                            {[
                                employee.role,
                                employee.yard,
                                employee.pay_type,
                                employee.payroll_id
                                    ? `Payroll ${employee.payroll_id}`
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </span>
                }
                actions={
                    editable &&
                    (timesheet.status === 'approved' ? (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    TimesheetController.unapprove.url(
                                        employee.id,
                                    ),
                                    { period_id: period.id },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Reopen
                        </Button>
                    ) : (
                        <Button
                            disabled={Boolean(timesheet.blocker)}
                            title={timesheet.blocker ?? undefined}
                            onClick={() =>
                                router.post(
                                    TimesheetController.approve.url(
                                        employee.id,
                                    ),
                                    { period_id: period.id },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Approve
                        </Button>
                    ))
                }
            />

            <InputError message={errors.time as string | undefined} />

            <StatGrid>
                <Stat label="Regular" value={totals.regular_label} />
                <Stat
                    label="Overtime"
                    value={totals.overtime_label}
                    tone={totals.overtime > 0 ? 'warning' : undefined}
                />
                <Stat label="Total" value={totals.total_label} />
                <Stat
                    label="Status"
                    value={
                        <Chip
                            tone={
                                timesheet.status === 'approved'
                                    ? 'success'
                                    : timesheet.status === 'submitted'
                                      ? 'info'
                                      : 'neutral'
                            }
                        >
                            {period.status !== 'open'
                                ? period.status_label
                                : timesheet.status_label}
                        </Chip>
                    }
                />
            </StatGrid>

            {timesheet.status === 'approved' && (
                <Notice tone="success">
                    Approved by {timesheet.approved_by} {timesheet.approved_at}.
                    Any change sends it back for approval.
                </Notice>
            )}
            {timesheet.status !== 'approved' && timesheet.blocker && (
                <Notice tone="warning">
                    Before approving: {timesheet.blocker}.
                </Notice>
            )}
            {!editable && (
                <Notice>
                    This pay period is {period.status_label.toLowerCase()}.
                    Fixes go into the current period as hours adjustments.
                </Notice>
            )}

            <Section
                title="Days"
                actions={
                    editable ? undefined : (
                        <button
                            type="button"
                            className="text-sm text-info hover:underline"
                            onClick={() => setShowEmpty((v) => !v)}
                        >
                            {showEmpty ? 'Hide days off' : 'Show every day'}
                        </button>
                    )
                }
            >
                <TimeDays
                    days={days}
                    showEmpty={showEmpty || editable}
                    punchActions={
                        editable ? (p) => <PunchActions punch={p} /> : undefined
                    }
                    adjustmentActions={(a) => <DisputeActions adjustment={a} />}
                    dayActions={
                        editable
                            ? (d) => (
                                  <DayFix
                                      day={d}
                                      employeeId={employee.id}
                                      punchTypes={punch_types}
                                      earnsOvertime={employee.earns_overtime}
                                  />
                              )
                            : undefined
                    }
                />
            </Section>
        </PageBody>
    );
}
