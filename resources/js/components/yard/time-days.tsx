import { AlertTriangle, Camera, MapPinOff, PencilLine } from 'lucide-react';
import type { ReactNode } from 'react';
import { Chip } from '@/components/yard/chip';
import { cn } from '@/lib/utils';
import type { Adjustment, Punch, TimeDay } from '@/types/time';

function PunchPill({
    punch,
    actions,
}: {
    punch: Punch;
    actions?: (punch: Punch) => ReactNode;
}) {
    const away = punch.geofence === 'outside';
    const noLocation = punch.geofence === 'unknown';

    return (
        <li className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            <span className="w-12 shrink-0 text-muted-foreground">
                {punch.label}
            </span>
            <span className="figure font-semibold">{punch.time}</span>
            <span className="text-xs text-muted-foreground">
                {punch.source_label}
                {punch.location ? ` · ${punch.location}` : ''}
            </span>
            {punch.adjusted && (
                <Chip tone="info">
                    <PencilLine className="mr-1 size-3" /> Edited
                </Chip>
            )}
            {away && <Chip tone="warning">Away from the yard</Chip>}
            {punch.geofence === 'take_home' && <Chip>Take-home truck</Chip>}
            {noLocation && (
                <Chip tone="warning">
                    <MapPinOff className="mr-1 size-3" /> No location
                </Chip>
            )}
            {punch.sent_late && <Chip tone="warning">Sent late</Chip>}
            {punch.photo_url && (
                <a
                    href={punch.photo_url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 text-xs text-info underline-offset-2 hover:underline"
                >
                    <Camera className="size-3" /> Photo
                </a>
            )}
            {actions?.(punch)}
        </li>
    );
}

function AdjustmentRow({
    adjustment,
    actions,
}: {
    adjustment: Adjustment;
    actions?: (adjustment: Adjustment) => ReactNode;
}) {
    return (
        <li className="rounded-lg bg-secondary/60 px-3 py-2 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span>
                    <span className="font-medium">
                        {adjustment.description}
                    </span>
                    <span className="text-muted-foreground">
                        {' '}
                        · {adjustment.by}
                        {adjustment.at ? `, ${adjustment.at}` : ''}
                    </span>
                </span>
                {adjustment.open_dispute ? (
                    <Chip tone="danger">Disputed</Chip>
                ) : adjustment.disputed && adjustment.resolved ? (
                    <Chip tone="neutral">Dispute sorted out</Chip>
                ) : null}
            </div>
            <div className="text-muted-foreground">
                Reason: {adjustment.reason}
            </div>
            {adjustment.dispute_note && (
                <div className="text-muted-foreground">
                    Dispute: {adjustment.dispute_note}
                </div>
            )}
            {actions?.(adjustment)}
        </li>
    );
}

/**
 * A timesheet's days: each shift's punches, breaks, edits and anything
 * that needs a look. Empty days are folded into one line.
 */
export function TimeDays({
    days,
    showEmpty = false,
    punchActions,
    adjustmentActions,
    dayActions,
}: {
    days: TimeDay[];
    showEmpty?: boolean;
    punchActions?: (punch: Punch) => ReactNode;
    adjustmentActions?: (adjustment: Adjustment) => ReactNode;
    dayActions?: (day: TimeDay) => ReactNode;
}) {
    const shown = showEmpty ? days : days.filter((d) => !d.empty);

    if (shown.length === 0) {
        return (
            <p className="rounded-[10px] border border-border bg-card px-4 py-8 text-center text-muted-foreground">
                No time recorded in this pay period yet.
            </p>
        );
    }

    return (
        <ol className="grid gap-3">
            {shown.map((day) => (
                <li
                    key={day.date}
                    className={cn(
                        'rounded-[10px] border bg-card p-4',
                        day.exceptions.length > 0
                            ? 'border-warning/40'
                            : 'border-border',
                    )}
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 className="font-semibold">
                            {day.label}
                            {day.is_today && (
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    Today
                                </span>
                            )}
                        </h3>
                        <div className="figure text-sm">
                            <span className="font-semibold">
                                {day.worked_label}
                            </span>
                            {day.overtime > 0 && (
                                <span className="ml-2 text-warning">
                                    incl. {day.overtime_label} overtime
                                </span>
                            )}
                        </div>
                    </div>

                    {day.shifts.map((shift, i) => (
                        <div
                            key={shift.punches[0]?.id ?? i}
                            className="mt-3 border-t border-secondary pt-3 first-of-type:border-t-0"
                        >
                            <ul className="grid gap-1.5">
                                {shift.punches.map((p) => (
                                    <PunchPill
                                        key={p.id}
                                        punch={p}
                                        actions={punchActions}
                                    />
                                ))}
                            </ul>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {shift.incomplete
                                    ? 'No clock-out: counts no hours until it’s fixed'
                                    : shift.open
                                      ? `On the clock · ${shift.worked_label} so far`
                                      : `${shift.worked_label} worked`}
                                {shift.breaks > 0 &&
                                    ` · ${shift.breaks} min ${shift.auto_meal ? 'meal deducted automatically' : 'on break'}`}
                            </p>
                        </div>
                    ))}

                    {day.strays.length > 0 && (
                        <ul className="mt-3 grid gap-1.5 border-t border-secondary pt-3">
                            {day.strays.map((p) => (
                                <PunchPill
                                    key={p.id}
                                    punch={p}
                                    actions={punchActions}
                                />
                            ))}
                        </ul>
                    )}

                    {day.exceptions.length > 0 && (
                        <ul className="mt-3 grid gap-1">
                            {day.exceptions.map((e, i) => (
                                <li
                                    key={`${e.kind}-${i}`}
                                    className="flex items-start gap-2 text-sm text-warning"
                                >
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    <span>
                                        <span className="font-semibold">
                                            {e.label}:
                                        </span>{' '}
                                        {e.detail}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {day.adjustments.length > 0 && (
                        <ul className="mt-3 grid gap-2">
                            {day.adjustments.map((a) => (
                                <AdjustmentRow
                                    key={a.id}
                                    adjustment={a}
                                    actions={adjustmentActions}
                                />
                            ))}
                        </ul>
                    )}

                    {dayActions?.(day)}
                </li>
            ))}
        </ol>
    );
}
