import { Link, router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import PaymentsController from '@/actions/App/Http/Controllers/Settings/PaymentsController';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { StepActions, StepHeader } from '@/components/setup/step';
import type { ChipTone } from '@/components/yard/chip';
import { Chip } from '@/components/yard/chip';
import { Button } from '@/components/ui/button';

type Props = {
    status: {
        state: string;
        label: string;
        tone: ChipTone;
        summary: string;
    };
    demo: boolean;
};

const USES = [
    'Payment links, on the counter tablet or sent to customers',
    'Saved cards and deposit holds for rentals',
    'Sales tax worked out from your tax registrations',
    'Payouts to your bank, already net of fees',
];

export default function SetupPayments({ status, demo }: Props) {
    const active = status.state === 'active';

    return (
        <>
            <StepHeader description="Take cards on payment links, hold rental deposits, and register for sales tax. You enter your business, bank and tax details once, here in YardRunner." />

            <div className="rounded-[10px] border border-border bg-card p-5">
                <div className="flex flex-wrap items-start gap-4">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-primary">
                        <CreditCard className="size-5" />
                    </span>
                    <div className="grid min-w-0 flex-1 gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold">Card payments</span>
                            <Chip tone={status.tone}>{status.label}</Chip>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {status.summary}
                        </p>
                    </div>
                </div>
                <ul className="mt-4 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                    {USES.map((use) => (
                        <li key={use} className="flex items-center gap-2">
                            <span className="size-1.5 rounded-full bg-primary" />
                            {use}
                        </li>
                    ))}
                </ul>
                {!active && !demo && (
                    <Button asChild className="mt-5">
                        <Link href={PaymentsController.edit()}>
                            {status.state === 'not_started'
                                ? 'Set up card payments'
                                : 'Finish setting up'}
                        </Link>
                    </Button>
                )}
            </div>

            <p className="mt-4 text-sm text-muted-foreground">
                It takes about ten minutes with your bank details and business
                number to hand. Cash, cheque, e-Transfer, your own card terminal
                and on-account sales all work without it.
            </p>

            <StepActions
                primary="Continue"
                skip={false}
                onPrimary={() =>
                    router.post(SetupController.next.url({ step: 'payments' }))
                }
            />
        </>
    );
}
