import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Page title, optional breadcrumb line and actions, as in the mockups.
 */
export function PageHeader({
    title,
    eyebrow,
    description,
    actions,
}: {
    title: ReactNode;
    eyebrow?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                {eyebrow && (
                    <div className="text-[13px] text-muted-foreground">
                        {eyebrow}
                    </div>
                )}
                <h1 className="text-[28px] leading-tight font-bold">{title}</h1>
                {description && (
                    <p className="mt-1 max-w-prose text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2.5">
                    {actions}
                </div>
            )}
        </header>
    );
}

export function PageBody({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex w-full flex-col gap-5 px-4 py-6 md:px-8 md:py-7',
                className,
            )}
        >
            {children}
        </div>
    );
}

const InCard = createContext(false);

/** True inside a Section card: tables drop their own frame. */
export function useInCard(): boolean {
    return useContext(InCard);
}

/**
 * A white card with an optional heading. `flush` removes the padding so a
 * table can run edge to edge.
 */
export function Section({
    title,
    description,
    actions,
    children,
    className,
    flush = false,
}: {
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    flush?: boolean;
}) {
    return (
        <section
            className={cn(
                'flex min-w-0 flex-col gap-3 rounded-[10px] border border-border bg-card',
                flush ? 'overflow-hidden' : 'p-5',
                className,
            )}
        >
            {(title || actions) && (
                <div
                    className={cn(
                        'flex items-baseline justify-between gap-4',
                        flush && 'px-5 pt-4',
                    )}
                >
                    {title && (
                        <div className="min-w-0">
                            <h2 className="text-base font-semibold">{title}</h2>
                            {description && (
                                <p className="mt-0.5 max-w-prose text-sm text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {actions}
                </div>
            )}
            <InCard.Provider value={true}>{children}</InCard.Provider>
        </section>
    );
}

/**
 * A figure tile: muted label above a large monospaced number.
 */
export function Stat({
    label,
    value,
    tone,
}: {
    label: ReactNode;
    value: ReactNode;
    tone?: 'danger' | 'warning' | 'success';
}) {
    return (
        <div className="rounded-[10px] border border-border bg-card p-4">
            <div className="text-muted-foreground">{label}</div>
            <div
                className={cn(
                    'figure mt-1.5 text-[28px] leading-tight',
                    tone === 'danger' && 'text-destructive',
                    tone === 'warning' && 'text-[#a16207] dark:text-warning',
                    tone === 'success' && 'text-success',
                )}
            >
                {value}
            </div>
        </div>
    );
}

export function StatGrid({ children }: { children: ReactNode }) {
    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            {children}
        </div>
    );
}

/**
 * A one-line notice: conflicts, availability, credit warnings.
 */
export function Notice({
    tone = 'info',
    children,
    className,
}: {
    tone?: 'info' | 'success' | 'warning' | 'danger';
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            role={tone === 'danger' ? 'alert' : undefined}
            className={cn(
                'rounded-lg border px-3.5 py-3 text-sm',
                tone === 'info' && 'border-border bg-accent text-foreground',
                tone === 'success' &&
                    'border-success/20 bg-success-soft text-success',
                tone === 'warning' &&
                    'border-warning/20 bg-warning-soft text-warning',
                tone === 'danger' &&
                    'border-danger/20 bg-danger-soft text-danger',
                className,
            )}
        >
            {children}
        </div>
    );
}
