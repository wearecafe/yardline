import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

type Editing = 'pickup' | 'return';

const WEEKDAYS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

function parse(day: string): Date {
    return new Date(`${day}T12:00:00Z`);
}

export function addDays(day: string, n: number): string {
    const d = parse(day);
    d.setUTCDate(d.getUTCDate() + n);

    return d.toISOString().slice(0, 10);
}

/** The first of the month, as YYYY-MM-01. */
export function monthOf(day: string): string {
    return `${day.slice(0, 7)}-01`;
}

export function daysInMonth(month: string): number {
    const d = parse(month);

    return new Date(
        Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0),
    ).getUTCDate();
}

function addMonths(month: string, n: number): string {
    const d = parse(month);

    return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1))
        .toISOString()
        .slice(0, 10);
}

function format(day: string, options: Intl.DateTimeFormatOptions): string {
    return parse(day).toLocaleDateString('en-CA', {
        ...options,
        timeZone: 'UTC',
    });
}

/**
 * Pick-up and return dates for the booking page: two large fields over one
 * month calendar that shows which days are already booked. Replaces a pair
 * of native date inputs, which don't fit a narrow column.
 */
export function BookingDates({
    today,
    pickup,
    returnOn,
    booked,
    month,
    onMonthChange,
    onChange,
}: {
    today: string;
    pickup: string;
    returnOn: string;
    /** Days (YYYY-MM-DD) when nothing is free. */
    booked: Set<string>;
    /** The month shown, YYYY-MM-01. */
    month: string;
    onMonthChange: (month: string) => void;
    onChange: (pickup: string, returnOn: string) => void;
}) {
    const [editing, setEditing] = useState<Editing>('pickup');

    // While choosing a return, the range can't run through a booked day.
    let stop: string | null = null;
    if (editing === 'return') {
        for (let i = 1; i <= 62; i++) {
            const day = addDays(pickup, i);
            if (booked.has(day)) {
                stop = day;
                break;
            }
        }
    }

    const spansBooked = (from: string, to: string) => {
        for (let d = from; d <= to; d = addDays(d, 1)) {
            if (booked.has(d)) {
                return true;
            }
        }

        return false;
    };

    const choose = (day: string) => {
        if (editing === 'pickup' || day < pickup) {
            onChange(
                day,
                returnOn >= day && !spansBooked(day, returnOn) ? returnOn : day,
            );
            setEditing('return');

            return;
        }

        onChange(pickup, day);
        setEditing('pickup');
    };

    const lead = parse(month).getUTCDay();
    const cells: (string | null)[] = [
        ...Array.from({ length: lead }, () => null),
        ...Array.from({ length: daysInMonth(month) }, (_, i) =>
            addDays(month, i),
        ),
    ];
    const canGoBack = month > monthOf(today);

    const field = (which: Editing, label: string, value: string) => (
        <button
            type="button"
            aria-pressed={editing === which}
            aria-label={`${label} date, ${format(value, { weekday: 'long', month: 'long', day: 'numeric' })}. Choose on the calendar.`}
            onClick={() => {
                setEditing(which);
                onMonthChange(monthOf(value));
            }}
            className={cn(
                'flex min-h-[60px] min-w-0 flex-col justify-center gap-0.5 px-3 py-2 text-left',
                editing === which
                    ? 'bg-accent-soft shadow-[inset_0_-3px_0_var(--color-primary)]'
                    : 'bg-card hover:bg-accent',
            )}
        >
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="truncate font-semibold">
                {format(value, {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                })}
            </span>
        </button>
    );

    return (
        <fieldset className="flex min-w-0 flex-col gap-2.5">
            <legend className="mb-2 text-xs text-muted-foreground">
                Rental dates
            </legend>
            <div className="grid grid-cols-2 divide-x divide-input overflow-hidden rounded-[10px] border border-input">
                {field('pickup', 'Pick up', pickup)}
                {field('return', 'Return', returnOn)}
            </div>

            <div className="flex flex-col gap-2 rounded-[10px] border border-border p-3">
                <div className="flex items-center justify-between">
                    <button
                        type="button"
                        aria-label="Previous month"
                        disabled={!canGoBack}
                        onClick={() => onMonthChange(addMonths(month, -1))}
                        className="flex size-11 items-center justify-center rounded-lg hover:bg-accent disabled:opacity-30"
                    >
                        <ChevronLeft className="size-[18px]" aria-hidden />
                    </button>
                    <div className="font-semibold" aria-live="polite">
                        {format(month, { month: 'long', year: 'numeric' })}
                    </div>
                    <button
                        type="button"
                        aria-label="Next month"
                        onClick={() => onMonthChange(addMonths(month, 1))}
                        className="flex size-11 items-center justify-center rounded-lg hover:bg-accent"
                    >
                        <ChevronRight className="size-[18px]" aria-hidden />
                    </button>
                </div>
                <div
                    aria-hidden
                    className="grid grid-cols-7 text-center text-xs text-muted-foreground"
                >
                    {WEEKDAYS.map((d, i) => (
                        <span key={i}>{d}</span>
                    ))}
                </div>
                <div
                    className="grid grid-cols-7 gap-y-1"
                    role="group"
                    aria-label={`Choose the ${editing === 'pickup' ? 'pick-up' : 'return'} date`}
                >
                    {cells.map((day, i) => {
                        if (day === null) {
                            return <span key={`blank-${i}`} aria-hidden />;
                        }

                        const isBooked = booked.has(day);
                        const past = day < today;
                        const beyond =
                            stop !== null && day >= stop && day >= pickup;
                        const disabled = past || isBooked || beyond;
                        const start = day === pickup;
                        const end = day === returnOn;
                        const inside = day > pickup && day < returnOn;
                        const note = start
                            ? ', pick up'
                            : end
                              ? ', return'
                              : inside
                                ? ', in your rental'
                                : isBooked
                                  ? ', booked'
                                  : '';

                        return (
                            <button
                                key={day}
                                type="button"
                                disabled={disabled}
                                aria-pressed={start || end || inside}
                                aria-label={`${format(day, { weekday: 'long', month: 'long', day: 'numeric' })}${note}`}
                                onClick={() => choose(day)}
                                className={cn(
                                    'h-10 min-w-0 text-sm',
                                    start || end
                                        ? 'bg-primary font-bold text-white'
                                        : inside
                                          ? 'bg-accent-soft font-semibold text-[#7c2d12] dark:text-[#fed7aa]'
                                          : 'rounded-lg hover:bg-accent',
                                    start && end && 'rounded-lg',
                                    start && !end && 'rounded-l-lg',
                                    end && !start && 'rounded-r-lg',
                                    disabled &&
                                        'text-[#a8a29e] hover:bg-transparent',
                                    isBooked && 'line-through',
                                )}
                            >
                                {Number(day.slice(8))}
                            </button>
                        );
                    })}
                </div>
                <p className="text-xs text-muted-foreground">
                    {editing === 'pickup'
                        ? 'Choose your pick-up day.'
                        : 'Now choose your return day.'}{' '}
                    Struck-through days are booked.
                </p>
            </div>
        </fieldset>
    );
}
