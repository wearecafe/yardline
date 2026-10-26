import type { ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Dense, ruled tables. Numbers right-aligned in tabular figures.
 */
export function Table({ children }: { children: ReactNode }) {
    return (
        <div className="overflow-x-auto rounded-md border border-border bg-card">
            <table className="w-full text-sm">{children}</table>
        </div>
    );
}

export function Th({
    className,
    numeric,
    ...props
}: ThHTMLAttributes<HTMLTableCellElement> & { numeric?: boolean }) {
    return (
        <th
            className={cn(
                'border-b border-border bg-muted/60 px-3 py-2 text-left font-medium whitespace-nowrap text-muted-foreground',
                numeric && 'text-right',
                className,
            )}
            {...props}
        />
    );
}

export function Td({
    className,
    numeric,
    ...props
}: TdHTMLAttributes<HTMLTableCellElement> & { numeric?: boolean }) {
    return (
        <td
            className={cn(
                'border-b border-border/70 px-3 py-2 align-middle',
                numeric && 'text-right whitespace-nowrap tabular-nums',
                className,
            )}
            {...props}
        />
    );
}

export function EmptyRow({
    colSpan,
    children,
}: {
    colSpan: number;
    children: ReactNode;
}) {
    return (
        <tr>
            <td
                colSpan={colSpan}
                className="px-3 py-10 text-center text-muted-foreground"
            >
                {children}
            </td>
        </tr>
    );
}
