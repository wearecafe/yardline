import type { ReactNode } from 'react';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { cn } from '@/lib/utils';

export type Health = { label: string; tone: ChipTone };

export type TenantStatus =
    | 'trial'
    | 'active'
    | 'past_due'
    | 'expired'
    | 'suspended'
    | 'cancelled';

const statuses: Record<TenantStatus, [string, ChipTone]> = {
    trial: ['Trial', 'info'],
    active: ['Active', 'success'],
    past_due: ['Past due', 'warning'],
    expired: ['Trial ended', 'neutral'],
    suspended: ['Suspended', 'danger'],
    cancelled: ['Cancelled', 'neutral'],
};

export function tenantStatusLabel(status: TenantStatus): string {
    return statuses[status][0];
}

export function TenantStatusChip({ status }: { status: TenantStatus }) {
    const [label, tone] = statuses[status];

    return <Chip tone={tone}>{label}</Chip>;
}

export function HealthChip({ health }: { health: Health }) {
    return <Chip tone={health.tone}>{health.label}</Chip>;
}

/**
 * A key figure with a muted note under it (change, context).
 */
export function Figure({
    label,
    value,
    note,
    noteTone,
}: {
    label: ReactNode;
    value: ReactNode;
    note?: ReactNode;
    noteTone?: 'up' | 'down' | 'muted';
}) {
    return (
        <div className="flex flex-col gap-1 rounded-[10px] border border-border bg-card p-4">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="figure text-[26px] leading-tight">{value}</div>
            {note !== undefined && (
                <div
                    className={cn(
                        'text-[13px]',
                        noteTone === 'up' && 'font-medium text-success',
                        noteTone === 'down' && 'font-medium text-danger',
                        (noteTone === undefined || noteTone === 'muted') &&
                            'text-muted-foreground',
                    )}
                >
                    {note}
                </div>
            )}
        </div>
    );
}

/**
 * A tiny bar sparkline. Decorative: the number beside it carries the value.
 */
export function Spark({ values }: { values: number[] }) {
    const max = Math.max(1, ...values);

    return (
        <div
            aria-hidden="true"
            className="flex h-6 w-24 shrink-0 items-end gap-px"
        >
            {values.map((v, i) => (
                <span
                    key={i}
                    className="flex-1 rounded-[1px] bg-primary/70"
                    style={{ height: `${Math.max(6, (v / max) * 100)}%` }}
                />
            ))}
        </div>
    );
}

export type BarSeries = { key: string; label: string; className: string };

/**
 * Vertical bars, stacked when there is more than one series. Each bar can
 * show its total above and a label below.
 */
export function BarChart<T extends Record<string, unknown>>({
    data,
    series,
    label,
    height = 180,
    xLabel,
    showTotals = false,
    formatTotal = (n) => n.toLocaleString('en-CA'),
    everyNthLabel = 1,
}: {
    data: T[];
    series: BarSeries[];
    label: string;
    height?: number;
    xLabel: (row: T) => string;
    showTotals?: boolean;
    formatTotal?: (n: number) => string;
    everyNthLabel?: number;
}) {
    const totals = data.map((row) =>
        series.reduce((sum, s) => sum + Number(row[s.key] ?? 0), 0),
    );
    const max = Math.max(1, ...totals);

    return (
        <figure className="flex flex-col gap-2">
            <div
                role="img"
                aria-label={label}
                className="flex items-end gap-[3px] border-b border-border sm:gap-1.5"
                style={{ height }}
            >
                {data.map((row, i) => (
                    <div
                        key={i}
                        className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1"
                        title={`${xLabel(row)}: ${series
                            .map((s) => `${s.label} ${Number(row[s.key] ?? 0)}`)
                            .join(', ')}`}
                    >
                        {showTotals && (
                            <span className="figure hidden text-[11px] text-muted-foreground sm:block">
                                {formatTotal(totals[i])}
                            </span>
                        )}
                        <div
                            className="flex w-full max-w-10 flex-col-reverse overflow-hidden rounded-t-[2px]"
                            style={{ height: `${(totals[i] / max) * 85}%` }}
                        >
                            {series.map((s) => (
                                <span
                                    key={s.key}
                                    className={cn('block w-full', s.className)}
                                    style={{
                                        height: `${totals[i] === 0 ? 0 : (Number(row[s.key] ?? 0) / totals[i]) * 100}%`,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
            <div className="flex gap-[3px] sm:gap-1.5">
                {data.map((row, i) => (
                    <span
                        key={i}
                        className="min-w-0 flex-1 truncate text-center text-[11px] text-muted-foreground"
                    >
                        {i % everyNthLabel === 0 ? xLabel(row) : ''}
                    </span>
                ))}
            </div>
            {series.length > 1 && (
                <figcaption className="flex flex-wrap gap-4 text-[13px] text-muted-foreground">
                    {series.map((s) => (
                        <span
                            key={s.key}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                className={cn(
                                    'size-2.5 rounded-[2px]',
                                    s.className,
                                )}
                            />
                            {s.label}
                        </span>
                    ))}
                </figcaption>
            )}
        </figure>
    );
}

/** A labelled horizontal bar for a percentage. */
export function PercentBar({
    label,
    percent,
}: {
    label: ReactNode;
    percent: number;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex justify-between gap-3 text-sm">
                <span>{label}</span>
                <span className="figure">{percent}%</span>
            </div>
            <div className="h-2 rounded bg-secondary">
                <div
                    className="h-2 rounded bg-foreground"
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

/** The two-letter connection badges in the tenant list. */
export function ConnectionBadges({
    connections,
}: {
    connections: { code: string; label: string; ok: boolean }[];
}) {
    if (connections.length === 0) {
        return <span className="text-muted-foreground">None</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {connections.map((c) => (
                <span
                    key={c.code}
                    title={`${c.label}${c.ok ? '' : ' — error'}`}
                    className={cn(
                        'figure rounded-[4px] px-1.5 py-0.5 text-[11px] font-semibold',
                        c.ok
                            ? 'border border-input text-foreground'
                            : 'bg-danger-soft text-danger',
                    )}
                >
                    {c.code}
                    <span className="sr-only">
                        {' '}
                        {c.label}
                        {c.ok ? '' : ' has an error'}
                    </span>
                </span>
            ))}
        </div>
    );
}
