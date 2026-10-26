import type { ReactNode, SelectHTMLAttributes } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export function Field({
    label,
    htmlFor,
    error,
    hint,
    className,
    children,
}: {
    label: ReactNode;
    htmlFor: string;
    error?: string;
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-1.5', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * A plain select, styled to match inputs. Native selects behave best on the
 * tablets used out in the yard.
 */
export function NativeSelect({
    className,
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            className={cn(
                'h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30',
                'disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        >
            {children}
        </select>
    );
}

export function Checkbox({
    id,
    label,
    checked,
    onChange,
    hint,
    disabled,
}: {
    id: string;
    label: ReactNode;
    checked: boolean;
    onChange: (checked: boolean) => void;
    hint?: ReactNode;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-start gap-2.5">
            <input
                id={id}
                type="checkbox"
                className="mt-0.5 size-4 rounded border-input accent-foreground"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
            />
            <div className="grid gap-0.5">
                <Label htmlFor={id}>{label}</Label>
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
            </div>
        </div>
    );
}
