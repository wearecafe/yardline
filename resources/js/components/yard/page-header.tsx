import type { ReactNode } from 'react';

/**
 * Page title with the measuring-tape rule beneath it.
 */
export function PageHeader({
    title,
    description,
    actions,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <header className="mb-6">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3 pb-3">
                <div className="min-w-0">
                    <h1 className="font-display text-3xl leading-tight font-semibold tracking-tight">
                        {title}
                    </h1>
                    {description && (
                        <p className="mt-1 max-w-prose text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
            <div className="tape-rule" aria-hidden="true" />
        </header>
    );
}

export function PageBody({ children }: { children: ReactNode }) {
    return (
        <div className="mx-auto w-full max-w-6xl px-4 py-6 md:px-6">
            {children}
        </div>
    );
}

export function Section({
    title,
    actions,
    children,
    className,
}: {
    title?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={className}>
            {(title || actions) && (
                <div className="mb-3 flex items-baseline justify-between gap-4 border-b border-border pb-2">
                    {title && (
                        <h2 className="font-display text-lg font-semibold">
                            {title}
                        </h2>
                    )}
                    {actions}
                </div>
            )}
            {children}
        </section>
    );
}
