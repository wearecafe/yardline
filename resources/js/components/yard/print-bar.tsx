import { Link } from '@inertiajs/react';
import { ChevronLeft, Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

/**
 * The bar above a printable page. Hidden when printing.
 */
export function PrintBar({
    back,
    backLabel,
    children,
}: {
    back: string;
    backLabel: string;
    children?: ReactNode;
}) {
    return (
        <div className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 border-b border-border bg-background px-4 py-2.5 print:hidden">
            <Link
                href={back}
                className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ChevronLeft className="size-4" /> {backLabel}
            </Link>
            <div className="flex flex-wrap gap-2">
                {children}
                <Button onClick={() => window.print()}>
                    <Printer /> Print
                </Button>
            </div>
        </div>
    );
}
