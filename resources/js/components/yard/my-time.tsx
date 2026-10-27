import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import MyTimeController from '@/actions/App/Http/Controllers/Time/MyTimeController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/yard/chip';
import { TimeClockCard } from '@/components/yard/time-clock-card';
import { TimeDays } from '@/components/yard/time-days';
import type {
    Adjustment,
    ClockInfo,
    PayPeriodInfo,
    TimeDay,
    TimesheetInfo,
    TimeTotals,
} from '@/types/time';

export type MyTimeProps = {
    employee: {
        id: number;
        name: string;
        take_home_truck: boolean;
        earns_overtime: boolean;
    } | null;
    clock?: ClockInfo;
    period?: PayPeriodInfo;
    timesheet?: TimesheetInfo;
    days?: TimeDay[];
    totals?: TimeTotals;
};

function DisputeForm({ adjustment }: { adjustment: Adjustment }) {
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState('');
    const { errors } = usePage().props;

    if (adjustment.open_dispute || adjustment.resolved) {
        return null;
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mt-1 text-sm font-medium text-info hover:underline"
            >
                This isn’t right
            </button>
        );
    }

    return (
        <form
            className="mt-2 grid gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    MyTimeController.dispute.url(adjustment.id),
                    { note },
                    { preserveScroll: true, onSuccess: () => setOpen(false) },
                );
            }}
        >
            <textarea
                value={note}
                onChange={(e) => setNote(e.target.value)}
                rows={2}
                placeholder="What should it be? e.g. I clocked out at 5:00, not 4:10"
                className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm"
                aria-label="What's wrong with this change"
            />
            <InputError message={errors.note as string | undefined} />
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={note.trim() === ''}>
                    Send to my manager
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setOpen(false)}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * Someone's own clock and timesheet: punch in and out, see this pay
 * period's days, dispute a manager's change, and send it for approval.
 */
export function MyTime({
    employee,
    clock,
    period,
    timesheet,
    days,
    totals,
    dark = false,
}: MyTimeProps & { dark?: boolean }) {
    const { errors } = usePage().props;

    if (!employee || !clock || !period || !timesheet || !days || !totals) {
        return (
            <>
                <Head title="My time" />
                <div className="rounded-[10px] border border-border bg-card p-6 text-center">
                    <p className="font-semibold">You don’t clock in here</p>
                    <p className="text-sm text-muted-foreground">
                        Ask the office to set you up under Team → Employees.
                    </p>
                </div>
            </>
        );
    }

    const canSubmit =
        period.status === 'open' &&
        timesheet.status === 'open' &&
        totals.total > 0;

    return (
        <>
            <Head title="My time" />

            <div className="grid gap-5">
                <TimeClockCard clock={clock} dark={dark} />
                <InputError message={errors.time as string | undefined} />

                <section className="grid gap-3">
                    <div className="flex items-center justify-between gap-2">
                        <Link
                            href={MyTimeController.show.url({
                                query: { date: period.prev_date },
                            })}
                            className="rounded-lg p-2 text-muted-foreground hover:bg-secondary"
                            aria-label="Previous pay period"
                        >
                            <ChevronLeft className="size-5" />
                        </Link>
                        <div className="text-center">
                            <h2 className="font-semibold">
                                {period.is_current
                                    ? 'This pay period'
                                    : 'Pay period'}
                            </h2>
                            <div className="text-sm text-muted-foreground">
                                {period.label}
                            </div>
                        </div>
                        {period.next_date ? (
                            <Link
                                href={MyTimeController.show.url({
                                    query: { date: period.next_date },
                                })}
                                className="rounded-lg p-2 text-muted-foreground hover:bg-secondary"
                                aria-label="Next pay period"
                            >
                                <ChevronRight className="size-5" />
                            </Link>
                        ) : (
                            <span className="size-9" />
                        )}
                    </div>

                    <div className="grid grid-cols-3 gap-3 rounded-[10px] border border-border bg-card p-4 text-center">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Regular
                            </div>
                            <div className="figure text-lg font-semibold">
                                {totals.regular_label}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Overtime
                            </div>
                            <div className="figure text-lg font-semibold">
                                {totals.overtime_label}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Status
                            </div>
                            <div className="mt-1">
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
                            </div>
                        </div>
                    </div>

                    {canSubmit && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    MyTimeController.submit.url(),
                                    { period_id: period.id },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Send this timesheet for approval
                        </Button>
                    )}
                    {timesheet.status === 'submitted' && (
                        <p className="text-center text-sm text-muted-foreground">
                            Sent for approval {timesheet.submitted_at}.
                        </p>
                    )}
                    {timesheet.status === 'approved' && (
                        <p className="text-center text-sm text-muted-foreground">
                            Approved {timesheet.approved_at}.
                        </p>
                    )}

                    <TimeDays
                        days={days}
                        adjustmentActions={(a) => (
                            <DisputeForm adjustment={a} />
                        )}
                    />
                </section>
            </div>
        </>
    );
}
