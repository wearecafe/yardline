import { usePage } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TabItem<T extends string> = {
    value: T;
    label: string;
    count?: number;
    content: ReactNode;
};

/**
 * Underlined tabs for splitting a long settings page into sections. The
 * open tab is kept in the URL (?tab=), so saving a form, which redirects
 * back to the same address, comes back to the tab you were on.
 */
export function Tabs<T extends string>({
    items,
    label,
    param = 'tab',
}: {
    items: TabItem<T>[];
    label: string;
    param?: string;
}) {
    const { url } = usePage();
    const id = useId();
    const buttons = useRef<(HTMLButtonElement | null)[]>([]);

    const [active, setActive] = useState<T>(() => {
        const requested = new URL(url, 'http://localhost').searchParams.get(
            param,
        );

        return (
            items.find((item) => item.value === requested)?.value ??
            items[0].value
        );
    });

    const select = (value: T) => {
        setActive(value);

        if (typeof window !== 'undefined') {
            const next = new URL(window.location.href);

            if (value === items[0].value) {
                next.searchParams.delete(param);
            } else {
                next.searchParams.set(param, value);
            }

            window.history.replaceState(window.history.state, '', next);
        }
    };

    const onKeyDown = (e: KeyboardEvent, index: number) => {
        const moves: Record<string, number> = {
            ArrowRight: index + 1,
            ArrowLeft: index - 1,
            Home: 0,
            End: items.length - 1,
        };

        if (!(e.key in moves)) {
            return;
        }

        e.preventDefault();
        const to = (moves[e.key] + items.length) % items.length;
        select(items[to].value);
        buttons.current[to]?.focus();
    };

    return (
        <div className="space-y-6">
            <div
                role="tablist"
                aria-label={label}
                className="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1"
            >
                {items.map((item, index) => {
                    const selected = item.value === active;

                    return (
                        <button
                            key={item.value}
                            ref={(el) => {
                                buttons.current[index] = el;
                            }}
                            type="button"
                            role="tab"
                            id={`${id}-tab-${item.value}`}
                            aria-selected={selected}
                            aria-controls={`${id}-panel-${item.value}`}
                            tabIndex={selected ? 0 : -1}
                            onClick={() => select(item.value)}
                            onKeyDown={(e) => onKeyDown(e, index)}
                            className={cn(
                                '-mb-px flex h-10 items-center gap-1.5 border-b-2 px-3 text-sm whitespace-nowrap transition-colors',
                                selected
                                    ? 'border-primary font-semibold text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {item.label}
                            {item.count !== undefined && (
                                <span className="rounded-full bg-secondary px-1.5 py-0.5 text-xs leading-none font-medium text-muted-foreground tabular-nums">
                                    {item.count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            {items.map((item) => (
                <div
                    key={item.value}
                    role="tabpanel"
                    id={`${id}-panel-${item.value}`}
                    aria-labelledby={`${id}-tab-${item.value}`}
                    hidden={item.value !== active}
                >
                    {item.content}
                </div>
            ))}
        </div>
    );
}
