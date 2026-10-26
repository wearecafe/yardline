import type { ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { useInCard } from '@/components/yard/page-header';
import { cn } from '@/lib/utils';

/**
 * Ruled tables as in the mockups. Inside a Section card the table runs
 * edge to edge; on its own it gets its own card.
 */
export function Table({
    children,
    minWidth,
}: {
    children: ReactNode;
    minWidth?: number;
}) {
    const inCard = useInCard();

    return (
        <div
            className={cn(
                'overflow-x-auto',
                !inCard && 'rounded-[10px] border border-border bg-card',
            )}
        >
            <table
                className="w-full border-collapse text-sm"
                style={minWidth ? { minWidth } : undefined}
            >
                {children}
            </table>
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
                'border-b border-border px-4 py-3 text-left text-xs font-medium tracking-[0.04em] whitespace-nowrap text-muted-foreground uppercase',
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
                'border-b border-secondary px-4 py-3 align-middle',
                numeric && 'figure text-right whitespace-nowrap',
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
                className="px-4 py-10 text-center text-muted-foreground"
            >
                {children}
            </td>
        </tr>
    );
}
