import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    const previous = page.links[0];
    const next = page.links[page.links.length - 1];

    return (
        <nav
            className="mt-4 flex items-center justify-between text-sm text-muted-foreground"
            aria-label="Pagination"
        >
            <span>
                {page.from}–{page.to} of {page.total}
            </span>
            <div className="flex gap-1">
                {[
                    { link: previous, label: 'Previous' },
                    { link: next, label: 'Next' },
                ].map(({ link, label }) =>
                    link?.url ? (
                        <Link
                            key={label}
                            href={link.url}
                            preserveScroll
                            className="rounded-md border border-border px-3 py-1.5 text-foreground hover:bg-accent"
                        >
                            {label}
                        </Link>
                    ) : (
                        <span
                            key={label}
                            className={cn(
                                'rounded-md border border-border px-3 py-1.5 opacity-50',
                            )}
                        >
                            {label}
                        </span>
                    ),
                )}
            </div>
        </nav>
    );
}
