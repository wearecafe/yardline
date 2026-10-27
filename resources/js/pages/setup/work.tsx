import { useForm } from '@inertiajs/react';
import { Clock, Construction, HardHat, Truck } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { StepActions, StepHeader } from '@/components/setup/step';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Answers = {
    delivers: boolean;
    contractors: boolean;
    rentals: boolean;
    time: boolean;
};

type Props = {
    answers: Answers;
    prices: { rentals_cents: number; time_cents: number; trial_days: number };
};

export default function SetupWork({ answers, prices }: Props) {
    const form = useForm<Answers>(answers);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(SetupController.work.url());
    }

    const option = (
        key: keyof Answers,
        icon: ReactNode,
        title: string,
        detail: ReactNode,
        price?: string,
    ) => (
        <label
            className={cn(
                'flex cursor-pointer items-start gap-4 rounded-[10px] border bg-card p-4 transition-colors',
                form.data[key]
                    ? 'border-primary ring-1 ring-primary'
                    : 'border-border hover:border-input',
            )}
        >
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-lg',
                    form.data[key]
                        ? 'bg-accent-soft text-primary'
                        : 'bg-muted text-subtle',
                )}
            >
                {icon}
            </span>
            <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="flex flex-wrap items-baseline gap-x-2 font-semibold">
                    {title}
                    {price && (
                        <span className="text-xs font-medium text-muted-foreground">
                            Add-on · {price}
                        </span>
                    )}
                </span>
                <span className="text-sm text-muted-foreground">{detail}</span>
            </span>
            <input
                type="checkbox"
                className="mt-1 size-5 shrink-0 rounded border-input accent-[#c2410c]"
                checked={form.data[key]}
                onChange={(e) => form.setData(key, e.target.checked)}
            />
        </label>
    );

    return (
        <>
            <StepHeader description="Tick what applies. It decides what YardRunner suggests next, and switches on the add-ons you need. You can change any of it later." />
            <form onSubmit={submit} className="grid gap-3">
                {option(
                    'delivers',
                    <Truck className="size-5" />,
                    'We deliver with our own trucks',
                    'Delivery fees on orders, a dispatch board and a run for each driver on their phone.',
                )}
                {option(
                    'contractors',
                    <HardHat className="size-5" />,
                    'We sell to contractors on account',
                    'Accounts with terms, credit limits, job sites, trade price lists and a portal to reorder from.',
                )}
                {option(
                    'rentals',
                    <Construction className="size-5" />,
                    'We rent out equipment',
                    'Fleet, availability, agreements, deposits, inspections and online booking.',
                    `${formatMoney(prices.rentals_cents)} a month`,
                )}
                {option(
                    'time',
                    <Clock className="size-5" />,
                    'We track staff hours',
                    'Clock in at the counter tablet or by phone, timesheets, overtime and payroll exports.',
                    `${formatMoney(prices.time_cents)} a person a month`,
                )}
                <p className="mt-2 text-sm text-muted-foreground">
                    Add-ons are free for the rest of your {prices.trial_days}
                    -day trial. Switch them off any time under Settings →
                    Billing.
                </p>
                <StepActions processing={form.processing} />
            </form>
        </>
    );
}
