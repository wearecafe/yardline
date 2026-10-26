import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';

/**
 * Filters the current page via the query string as you type.
 */
export function SearchBox({
    value,
    placeholder,
    extra = {},
}: {
    value: string | undefined;
    placeholder: string;
    extra?: Record<string, string | number | undefined>;
}) {
    const [term, setTerm] = useState(value ?? '');
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                window.location.pathname,
                { ...extra, search: term || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 250);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className="relative w-full max-w-sm">
            <Search
                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                type="search"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={placeholder}
                aria-label={placeholder}
                className="pl-8"
            />
        </div>
    );
}
