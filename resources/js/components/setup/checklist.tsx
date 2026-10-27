import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { Section } from '@/components/yard/page-header';
import { cn } from '@/lib/utils';
import type { ChecklistItem, SetupChecklist } from '@/types';

/** What's left after Setup, each item linking to where it's done. */
export function ChecklistItems({ items }: { items: ChecklistItem[] }) {
    return (
        <ul className="divide-y divide-border">
            {items.map((item) => (
                <li key={item.key}>
                    <Link
                        href={item.href}
                        className={cn(
                            'group flex items-center gap-3.5 px-5 py-3.5 transition-colors hover:bg-accent',
                            item.done && 'text-muted-foreground',
                        )}
                    >
                        <span
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center rounded-full',
                                item.done
                                    ? 'bg-success-soft text-success'
                                    : 'border-2 border-input',
                            )}
                            aria-label={item.done ? 'Done' : 'To do'}
                        >
                            {item.done && (
                                <Check className="size-3.5" strokeWidth={3} />
                            )}
                        </span>
                        <span className="grid min-w-0 flex-1 leading-snug">
                            <span
                                className={cn(
                                    'font-medium',
                                    item.done
                                        ? 'line-through decoration-muted-foreground/50'
                                        : 'text-foreground',
                                )}
                            >
                                {item.title}
                            </span>
                            {!item.done && (
                                <span className="text-sm text-muted-foreground">
                                    {item.detail}
                                </span>
                            )}
                        </span>
                        {!item.done && (
                            <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                        )}
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/** The "Finish setting up" card on Today, for owners and admins. */
export function SetupChecklistCard({ setup }: { setup: SetupChecklist }) {
    const percent = Math.round((setup.done / setup.total) * 100);

    return (
        <Section
            flush
            title="Finish setting up YardRunner"
            description={`${setup.done} of ${setup.total} done. Nothing here stops you selling today.`}
            actions={
                <button
                    type="button"
                    className="shrink-0 text-sm text-muted-foreground underline-offset-4 hover:underline"
                    onClick={() =>
                        router.post(
                            SetupController.hideChecklist.url(),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Hide
                </button>
            }
        >
            <div className="px-5">
                <div
                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-label="Setup progress"
                    aria-valuemin={0}
                    aria-valuemax={setup.total}
                    aria-valuenow={setup.done}
                >
                    <div
                        className="h-full rounded-full bg-primary"
                        style={{ width: `${percent}%` }}
                    />
                </div>
            </div>
            <ChecklistItems items={setup.items} />
        </Section>
    );
}
