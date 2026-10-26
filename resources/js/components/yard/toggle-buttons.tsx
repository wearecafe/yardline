import { cn } from '@/lib/utils';

/**
 * A row of mutually exclusive buttons (aria-pressed), as in the mockups'
 * "Customer picks up / We deliver" and fuel level choices.
 */
export function ToggleButtons<T extends string>({
    options,
    value,
    onChange,
    label,
    size = 'default',
    className,
}: {
    options: { value: T; label: string }[];
    value: T;
    onChange: (value: T) => void;
    label: string;
    size?: 'default' | 'sm';
    className?: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn('flex flex-wrap gap-2', className)}
        >
            {options.map((option) => {
                const pressed = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={pressed}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'rounded-lg border bg-card px-3.5 text-sm transition-colors',
                            size === 'sm' ? 'h-10 min-w-12' : 'h-11',
                            pressed
                                ? 'border-2 border-primary bg-accent-soft font-semibold'
                                : 'border-input hover:bg-accent',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
