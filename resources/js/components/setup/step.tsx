import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { SetupState } from '@/types';

export function useSetup(): SetupState {
    return usePage<{ setup: SetupState }>().props.setup;
}

/** "Step 2 of 7 · Required", the step's title and why it matters. */
export function StepHeader({
    description,
    title,
}: {
    description: ReactNode;
    title?: ReactNode;
}) {
    const setup = useSetup();
    const index = setup.steps.findIndex((s) => s.key === setup.step);

    return (
        <header className="mb-8">
            {index >= 0 && (
                <p className="text-[13px] font-medium text-muted-foreground">
                    Step {index + 1} of {setup.steps.length}
                    <span aria-hidden> · </span>
                    <span
                        className={
                            setup.required ? 'text-primary' : 'text-subtle'
                        }
                    >
                        {setup.required ? 'Required' : 'Optional'}
                    </span>
                </p>
            )}
            <h1 className="mt-1 text-[28px] leading-tight font-bold">
                {title ?? setup.title}
            </h1>
            <p className="mt-2 max-w-prose text-muted-foreground">
                {description}
            </p>
        </header>
    );
}

/**
 * Back, Skip for now (optional steps) and the main button. With no
 * `onPrimary`, the main button submits the step's form.
 */
export function StepActions({
    primary = 'Save and continue',
    processing = false,
    onPrimary,
    skip = true,
    form,
}: {
    primary?: ReactNode;
    processing?: boolean;
    onPrimary?: () => void;
    skip?: boolean | string;
    form?: string;
}) {
    const setup = useSetup();
    const canSkip = skip !== false && !setup.required;

    return (
        <div className="mt-10 flex flex-wrap items-center gap-3 border-t border-border pt-6">
            {setup.previous && (
                <Button variant="ghost" asChild className="-ml-3">
                    <Link href={SetupController.show({ step: setup.previous })}>
                        <ArrowLeft />
                        Back
                    </Link>
                </Button>
            )}
            <div className="ml-auto flex flex-wrap items-center gap-3">
                {canSkip && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                SetupController.next.url({ step: setup.step }),
                            )
                        }
                    >
                        {typeof skip === 'string' ? skip : 'Skip for now'}
                    </Button>
                )}
                <Button
                    type={onPrimary ? 'button' : 'submit'}
                    form={form}
                    disabled={processing}
                    onClick={onPrimary}
                    size="lg"
                >
                    {processing && <Spinner />}
                    {primary}
                </Button>
            </div>
        </div>
    );
}

/** A heading for a group of fields inside a step. */
export function StepSection({
    title,
    description,
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="grid gap-4">
            <div>
                <h2 className="text-base font-semibold">{title}</h2>
                {description && (
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}
