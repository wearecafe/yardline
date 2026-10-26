import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type ChipTone = 'success' | 'info' | 'danger' | 'warning' | 'neutral';

const tones: Record<ChipTone, string> = {
    success: 'bg-success-soft text-success',
    info: 'bg-info-soft text-info',
    danger: 'bg-danger-soft text-danger',
    warning: 'bg-warning-soft text-warning',
    neutral: 'bg-secondary text-subtle',
};

/** Pill-shaped status, as in the mockups. */
export function Chip({
    tone = 'neutral',
    children,
    className,
}: {
    tone?: ChipTone;
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-1 text-xs leading-none font-semibold whitespace-nowrap',
                tones[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
