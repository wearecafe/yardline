import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, LifeBuoy, LogOut } from 'lucide-react';
import type { ReactNode } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import type { SetupState } from '@/types';

/**
 * Setup, full screen: the steps down a dark rail on a desktop, a progress
 * bar on a phone or tablet, and the step itself beside them.
 */
export default function SetupLayout({ children }: { children: ReactNode }) {
    const { setup, tenant } = usePage<{
        setup: SetupState;
        tenant: { name: string } | null;
    }>().props;

    const index = setup.steps.findIndex((s) => s.key === setup.step);
    const total = setup.steps.length;
    const position = setup.step === 'done' ? total : index + 1;

    return (
        <div className="min-h-svh bg-background lg:grid lg:grid-cols-[18rem_minmax(0,1fr)] lg:bg-[linear-gradient(to_right,#1c1f1d_18rem,var(--background)_18rem)]">
            <Head title={`Set up · ${setup.title}`} />

            {/* The rail: desktop only. */}
            <aside className="hidden flex-col bg-[#1c1f1d] px-5 py-7 text-[#e7e5e4] lg:sticky lg:top-0 lg:flex lg:h-svh">
                <div className="flex items-center gap-2.5 px-1">
                    <div className="flex size-8 items-center justify-center rounded-md bg-[#c2410c] text-white">
                        <AppLogoIcon className="size-5 fill-current" />
                    </div>
                    <div className="grid leading-tight">
                        <span className="text-[17px] font-bold text-[#fafaf9]">
                            YardRunner
                        </span>
                        {tenant?.name && (
                            <span className="truncate text-xs text-[#a8a29e]">
                                {tenant.name}
                            </span>
                        )}
                    </div>
                </div>

                <p className="mt-9 px-1 text-xs font-semibold tracking-[0.08em] text-[#a8a29e] uppercase">
                    Set up your yard
                </p>
                <ol className="mt-3 grid gap-0.5">
                    {setup.steps.map((step, i) => (
                        <li key={step.key}>
                            <RailStep
                                number={i + 1}
                                step={step}
                                current={step.key === setup.step}
                            />
                        </li>
                    ))}
                </ol>

                <div className="mt-auto grid gap-1 border-t border-white/10 pt-4 text-sm">
                    <a
                        href="/help/getting-started/set-up-your-yard"
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-[#d6d3d1] hover:bg-white/5 hover:text-white"
                    >
                        <LifeBuoy className="size-4" />
                        Setup guide
                    </a>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                        className="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-[#d6d3d1] hover:bg-white/5 hover:text-white"
                    >
                        <LogOut className="size-4" />
                        Log out
                    </Link>
                </div>
            </aside>

            {/* Phone and tablet: name, step count and a progress bar. */}
            <header className="sticky top-0 z-10 border-b border-border bg-card lg:hidden">
                <div className="flex items-center justify-between gap-3 px-4 py-3">
                    <div className="flex min-w-0 items-center gap-2">
                        <div className="flex size-7 shrink-0 items-center justify-center rounded-md bg-[#c2410c] text-white">
                            <AppLogoIcon className="size-4 fill-current" />
                        </div>
                        <span className="truncate text-sm font-semibold">
                            {tenant?.name ?? 'YardRunner'}
                        </span>
                    </div>
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {setup.step === 'done'
                            ? 'All steps'
                            : `Step ${position} of ${total}`}
                    </span>
                </div>
                <div
                    className="h-1 bg-muted"
                    role="progressbar"
                    aria-label="Setup progress"
                    aria-valuemin={0}
                    aria-valuemax={total}
                    aria-valuenow={position}
                >
                    <div
                        className="h-full bg-primary transition-[width]"
                        style={{ width: `${(position / total) * 100}%` }}
                    />
                </div>
            </header>

            <main className="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 lg:py-14">
                {children}
            </main>
        </div>
    );
}

function RailStep({
    number,
    step,
    current,
}: {
    number: number;
    step: SetupState['steps'][number];
    current: boolean;
}) {
    const body = (
        <>
            <span
                className={cn(
                    'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    step.status === 'done'
                        ? 'bg-[#c2410c] text-white'
                        : current
                          ? 'bg-white text-[#1c1f1d]'
                          : 'border border-white/20 text-[#a8a29e]',
                )}
            >
                {step.status === 'done' ? (
                    <Check className="size-3.5" strokeWidth={3} />
                ) : (
                    number
                )}
            </span>
            <span className="grid min-w-0 leading-tight">
                <span className="truncate">{step.title}</span>
                {!step.required && step.status !== 'done' && (
                    <span className="text-xs text-[#a8a29e]">
                        {step.status === 'skipped' ? 'Skipped' : 'Optional'}
                    </span>
                )}
            </span>
        </>
    );

    const className = cn(
        'flex items-center gap-3 rounded-md px-2 py-2 text-sm',
        current
            ? 'bg-white/10 font-semibold text-white'
            : step.open
              ? 'text-[#d6d3d1] hover:bg-white/5 hover:text-white'
              : 'cursor-not-allowed text-[#78716c]',
    );

    if (!step.open || current) {
        return (
            <span
                className={className}
                aria-current={current ? 'step' : undefined}
            >
                {body}
            </span>
        );
    }

    return (
        <Link
            href={SetupController.show({ step: step.key })}
            className={className}
        >
            {body}
        </Link>
    );
}
