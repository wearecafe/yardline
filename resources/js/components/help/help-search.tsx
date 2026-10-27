import { Link, router } from '@inertiajs/react';
import { CornerDownLeft, FileText, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { HelpArticleSummary } from '@/components/help/help-layout';
import { getJson } from '@/lib/json';
import { cn } from '@/lib/utils';

export type MarkedPart = { text: string; hit: boolean };

export type HelpSearchResult = HelpArticleSummary & {
    categoryTitle: string;
    snippet: string;
    snippetParts: MarkedPart[];
    titleParts: MarkedPart[];
    section: { id: string; title: string } | null;
    complete: boolean;
};

export function articleUrl(article: { category: string; slug: string }) {
    return `/help/${article.category}/${article.slug}`;
}

/** The article, opened at the section that matched. */
export function resultUrl(result: HelpSearchResult) {
    return articleUrl(result) + (result.section ? `#${result.section.id}` : '');
}

/** Text with the matched words marked. */
export function Marked({ parts }: { parts: MarkedPart[] }) {
    return (
        <>
            {parts.map((part, i) =>
                part.hit ? (
                    <mark
                        key={i}
                        className="rounded-sm bg-primary/15 px-0.5 text-inherit"
                    >
                        {part.text}
                    </mark>
                ) : (
                    <span key={i}>{part.text}</span>
                ),
            )}
        </>
    );
}

type Suggestions = { query: string; results: HelpSearchResult[] };

/**
 * The help search box. Shows the best few articles as you type; Enter (or
 * "See all results") opens the full results page.
 */
export function HelpSearch({
    defaultValue = '',
    size = 'md',
    autoFocus = false,
}: {
    defaultValue?: string;
    size?: 'md' | 'lg';
    autoFocus?: boolean;
}) {
    const id = useId();
    const listId = `${id}-results`;
    const [value, setValue] = useState(defaultValue);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [found, setFound] = useState<Suggestions | null>(null);
    const [active, setActive] = useState(-1);
    const wrapper = useRef<HTMLDivElement>(null);

    const query = value.trim();
    const results =
        found !== null && found.query === query ? found.results : [];

    // The dropdown's options: each result, then "See all results".
    const optionCount = query === '' ? 0 : results.length + 1;

    useEffect(() => {
        if (query === '') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);

            getJson<Suggestions>(
                '/help/suggest',
                { q: query },
                controller.signal,
            )
                .then((data) => {
                    setFound(data);
                    setActive(-1);
                })
                .catch(() => undefined)
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setLoading(false);
                    }
                });
        }, 120);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (!wrapper.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const searchAll = () => {
        setOpen(false);
        router.get('/help/search', { q: query });
    };

    const choose = (index: number) => {
        if (index >= 0 && index < results.length) {
            setOpen(false);
            router.visit(resultUrl(results[index]));
        } else {
            searchAll();
        }
    };

    const showList = open && query !== '';
    const optionId = (index: number) => `${listId}-${index}`;

    return (
        <div ref={wrapper} className="relative w-full">
            <form
                action="/help/search"
                method="get"
                role="search"
                onSubmit={(event) => {
                    event.preventDefault();
                    choose(active);
                }}
            >
                <label htmlFor={id} className="sr-only">
                    Search help articles
                </label>
                <Search
                    className={cn(
                        'pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted-foreground',
                        size === 'lg' ? 'left-4 size-5' : 'left-3 size-4',
                    )}
                    aria-hidden
                />
                <input
                    id={id}
                    name="q"
                    type="search"
                    autoComplete="off"
                    autoFocus={autoFocus}
                    role="combobox"
                    aria-expanded={showList}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        showList && active >= 0 ? optionId(active) : undefined
                    }
                    value={value}
                    onChange={(event) => {
                        setValue(event.target.value);
                        setOpen(true);
                        setActive(-1);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown' && optionCount > 0) {
                            event.preventDefault();
                            setOpen(true);
                            setActive((i) => (i + 1) % optionCount);
                        } else if (event.key === 'ArrowUp' && optionCount > 0) {
                            event.preventDefault();
                            setOpen(true);
                            setActive((i) =>
                                i <= 0 ? optionCount - 1 : i - 1,
                            );
                        } else if (event.key === 'Escape') {
                            setOpen(false);
                            setActive(-1);
                        }
                    }}
                    placeholder="Search for pallet deposits, credit limits, HST…"
                    className={cn(
                        'w-full rounded-lg border border-input bg-background shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40',
                        size === 'lg'
                            ? 'h-14 pr-4 pl-12 text-base'
                            : 'h-10 pr-3 pl-9 text-sm',
                    )}
                />
            </form>

            {showList && (
                <div
                    id={listId}
                    role="listbox"
                    aria-label="Matching articles"
                    className="absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-xl border bg-popover text-left text-popover-foreground shadow-lg"
                >
                    {results.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">
                            {loading || found?.query !== query
                                ? 'Searching…'
                                : `No articles match “${query}”. Try other words.`}
                        </p>
                    ) : (
                        <ul className="max-h-[min(28rem,70vh)] overflow-y-auto py-1">
                            {results.map((result, index) => (
                                <li
                                    key={resultUrl(result)}
                                    id={optionId(index)}
                                    role="option"
                                    aria-selected={active === index}
                                >
                                    <Link
                                        href={resultUrl(result)}
                                        onClick={() => setOpen(false)}
                                        onMouseEnter={() => setActive(index)}
                                        className={cn(
                                            'flex gap-3 px-4 py-2.5',
                                            active === index &&
                                                'bg-secondary/70',
                                        )}
                                    >
                                        <FileText
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <span className="min-w-0">
                                            <span className="block text-sm font-medium">
                                                <Marked
                                                    parts={result.titleParts}
                                                />
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {result.categoryTitle}
                                                {result.section &&
                                                    ` › ${result.section.title}`}
                                            </span>
                                            <span className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                <Marked
                                                    parts={result.snippetParts}
                                                />
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}

                    <button
                        type="button"
                        id={optionId(results.length)}
                        role="option"
                        aria-selected={active === results.length}
                        onMouseEnter={() => setActive(results.length)}
                        onClick={searchAll}
                        className={cn(
                            'flex w-full items-center justify-between gap-3 border-t px-4 py-2.5 text-left text-sm',
                            active === results.length && 'bg-secondary/70',
                        )}
                    >
                        <span className="truncate">
                            See all results for “{query}”
                        </span>
                        <CornerDownLeft
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                    </button>
                </div>
            )}
        </div>
    );
}
