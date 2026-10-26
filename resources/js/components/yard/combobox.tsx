import { useId, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type ComboOption = {
    value: number;
    label: string;
    detail?: string | null;
    keywords?: string;
};

/**
 * Type-to-search picker. Arrow keys move, Enter picks, Escape closes.
 */
export function Combobox({
    id,
    options,
    value,
    onChange,
    placeholder,
    empty,
    footer,
}: {
    id?: string;
    options: ComboOption[];
    value: number | null;
    onChange: (value: number | null) => void;
    placeholder?: string;
    empty?: ReactNode;
    footer?: ReactNode;
}) {
    const fallbackId = useId();
    const inputId = id ?? fallbackId;
    const listId = `${inputId}-list`;
    const selected = options.find((o) => o.value === value) ?? null;
    const [query, setQuery] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const blurTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const shown = query ?? selected?.label ?? '';
    const matches = useMemo(() => {
        const q = (query ?? '').trim().toLowerCase();

        if (q === '') {
            return options.slice(0, 50);
        }

        return options
            .filter((o) =>
                `${o.label} ${o.detail ?? ''} ${o.keywords ?? ''}`
                    .toLowerCase()
                    .includes(q),
            )
            .slice(0, 50);
    }, [options, query]);

    function pick(option: ComboOption | null) {
        onChange(option?.value ?? null);
        setQuery(null);
        setOpen(false);
    }

    return (
        <div className="relative">
            <Input
                id={inputId}
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                autoComplete="off"
                value={shown}
                placeholder={placeholder}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setOpen(true);
                    setActive(0);

                    if (e.target.value === '') {
                        onChange(null);
                    }
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => {
                    blurTimer.current = setTimeout(() => {
                        setOpen(false);
                        setQuery(null);
                    }, 150);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setOpen(true);
                        setActive((a) => Math.min(a + 1, matches.length - 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setActive((a) => Math.max(a - 1, 0));
                    } else if (e.key === 'Enter' && open && matches[active]) {
                        e.preventDefault();
                        pick(matches[active]);
                    } else if (e.key === 'Escape') {
                        setOpen(false);
                        setQuery(null);
                    }
                }}
            />
            {open && (
                <div
                    id={listId}
                    role="listbox"
                    className="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg"
                    onMouseDown={() => {
                        if (blurTimer.current) {
                            clearTimeout(blurTimer.current);
                        }
                    }}
                >
                    {matches.length === 0 && (
                        <div className="px-3 py-2 text-sm text-muted-foreground">
                            {empty ?? 'No matches.'}
                        </div>
                    )}
                    {matches.map((option, i) => (
                        <button
                            key={option.value}
                            type="button"
                            role="option"
                            aria-selected={option.value === value}
                            className={cn(
                                'block w-full px-3 py-2 text-left text-sm',
                                i === active && 'bg-accent',
                            )}
                            onMouseEnter={() => setActive(i)}
                            onClick={() => pick(option)}
                        >
                            <span className="font-medium">{option.label}</span>
                            {option.detail && (
                                <span className="ml-2 text-muted-foreground">
                                    {option.detail}
                                </span>
                            )}
                        </button>
                    ))}
                    {footer && (
                        <div className="border-t border-border px-3 py-2 text-sm">
                            {footer}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
