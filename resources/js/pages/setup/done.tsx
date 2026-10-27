import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { ChecklistItems } from '@/components/setup/checklist';
import { useSetup } from '@/components/setup/step';
import { Button } from '@/components/ui/button';
import type { ChecklistItem } from '@/types';

type Props = {
    checklist: ChecklistItem[];
};

export default function SetupDone({ checklist }: Props) {
    const setup = useSetup();
    const done = setup.steps.filter((s) => s.status === 'done');
    const left = checklist.filter((item) => !item.done).length;

    return (
        <>
            <header className="mb-8">
                <span className="flex size-12 items-center justify-center rounded-full bg-success-soft text-success">
                    <Check className="size-6" strokeWidth={3} />
                </span>
                <h1 className="mt-5 text-[28px] leading-tight font-bold">
                    You’re ready to sell
                </h1>
                <p className="mt-2 max-w-prose text-muted-foreground">
                    Your business and yards are in, and {done.length} of{' '}
                    {setup.steps.length} setup steps are done.{' '}
                    {left > 0
                        ? `${left} more ${left === 1 ? 'thing' : 'things'} to do when you have a minute. They stay on Today until they’re done.`
                        : 'Everything on the list is done.'}
                </p>
            </header>

            {checklist.length > 0 && (
                <div className="overflow-hidden rounded-[10px] border border-border bg-card">
                    <ChecklistItems items={checklist} />
                </div>
            )}

            <div className="mt-10 flex flex-wrap items-center gap-3 border-t border-border pt-6">
                <Button
                    size="lg"
                    className="ml-auto"
                    onClick={() => router.post(SetupController.finish.url())}
                >
                    Go to Today
                </Button>
            </div>
        </>
    );
}
