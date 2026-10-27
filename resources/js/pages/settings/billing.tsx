import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Line = {
    key: 'base' | 'rentals' | 'time';
    name: string;
    unit_cents: number;
    quantity: number;
    per_user: boolean;
    total_cents: number;
    on: boolean;
};

type Props = {
    billing: {
        status:
            | 'trial'
            | 'active'
            | 'past_due'
            | 'expired'
            | 'suspended'
            | 'cancelled';
        status_label: string;
        has_access: boolean;
        subscribed: boolean;
        exempt: boolean;
        trial_days_left: number | null;
        trial_ends_at: string | null;
        period_ends_at: string | null;
        cancelling: boolean;
        subscription_status: string | null;
        has_customer: boolean;
        suspended: boolean;
    };
    lines: Line[];
    total_cents: number;
    currency: string;
    tracked: number;
};

const tones: Record<Props['billing']['status'], ChipTone> = {
    trial: 'info',
    active: 'success',
    past_due: 'warning',
    expired: 'neutral',
    suspended: 'danger',
    cancelled: 'neutral',
};

const blurbs: Record<Line['key'], string> = {
    base: 'Counter sales, orders and picking, dispatch and the driver app, inventory, purchasing, pricing, receivables and the contractor portal.',
    rentals:
        'Your rental fleet, calendar, agreements and e-signing, deposits, inspections, maintenance and the online booking page.',
    time: 'Time clock for the counter tablet and phones, timesheets, overtime and payroll exports. Billed for each person whose time you track.',
};

/** A date with no time ("2026-11-09"), shown as that day wherever you are. */
function formatDay(value: string | null): string {
    return value ? formatDate(`${value}T12:00:00`) : '';
}

function Switch({
    on,
    label,
    disabled,
    onChange,
}: {
    on: boolean;
    label: string;
    disabled?: boolean;
    onChange: (on: boolean) => void;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!on)}
            className={cn(
                'flex h-7 w-12 shrink-0 rounded-full p-0.5 transition-colors disabled:opacity-50',
                on ? 'justify-end bg-primary' : 'justify-start bg-[#a8a29e]',
            )}
        >
            <span className="size-6 rounded-full bg-white shadow-sm" />
        </button>
    );
}

function Status({
    billing,
    total,
    currency,
}: {
    billing: Props['billing'];
    total: string;
    currency: string;
}) {
    const [busy, setBusy] = useState(false);
    const go = (url: string) => {
        setBusy(true);
        router.post(url, {}, { onFinish: () => setBusy(false) });
    };
    const subscribe = (label: string) => (
        <Button
            disabled={busy}
            onClick={() => go(BillingController.checkout.url())}
        >
            {label}
        </Button>
    );
    const manage = (label = 'Manage billing') => (
        <Button
            variant="outline"
            disabled={busy}
            onClick={() => go(BillingController.portal.url())}
        >
            {label}
        </Button>
    );

    if (billing.exempt) {
        return (
            <p className="text-sm text-muted-foreground">
                The demo yard isn’t billed. Every add-on is on.
            </p>
        );
    }

    const days = billing.trial_days_left;
    const periodEnds = formatDate(billing.period_ends_at);

    let message: string;
    let actions: ReactNode = null;

    switch (billing.status) {
        case 'trial':
            if (billing.subscribed) {
                message = `Your payment details are saved. Your first bill, ${total} plus tax, is on ${periodEnds}.`;
                actions = manage();
            } else {
                message = `${days === 1 ? 'Today is the last day' : `${days} days left`} of your free trial (it ends ${formatDay(billing.trial_ends_at)}). Add your payment details any time: you won’t be charged until the trial ends.`;
                actions = subscribe('Add payment details');
            }

            break;
        case 'active':
            message = billing.cancelling
                ? `Your subscription ends on ${periodEnds}. To keep it, open Manage billing and renew.`
                : `Your next bill, ${total} plus tax, is on ${periodEnds}.`;
            actions = manage();
            break;
        case 'past_due':
            message =
                'Your last payment didn’t go through. Stripe will try again over the next few days; update your card to keep YardRunner running.';
            actions = manage('Update payment details');
            break;
        case 'expired':
            message =
                'Your free trial has ended. Add your payment details to pick up where you left off: everything you set up is still here.';
            actions = subscribe('Add payment details');
            break;
        case 'cancelled':
            message =
                'Your subscription has ended. Subscribe again to pick up where you left off: everything you set up is still here.';
            actions = (
                <>
                    {subscribe('Subscribe again')}
                    {billing.has_customer && manage('Past invoices')}
                </>
            );
            break;
        case 'suspended':
            message = billing.suspended
                ? 'Your account is suspended. Contact YardRunner support to have it restored.'
                : 'Your account is paused because a payment couldn’t be collected. Update your card to restore it.';
            actions =
                !billing.suspended && billing.has_customer
                    ? manage('Update payment details')
                    : null;
            break;
    }

    return (
        <div className="space-y-3 rounded-md border border-border bg-card p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">Subscription</span>
                <Chip tone={tones[billing.status]}>{billing.status_label}</Chip>
            </div>
            <p className="text-muted-foreground">{message}</p>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
            {billing.subscribed && (
                <p className="text-xs text-muted-foreground">
                    Card, billing address, invoices and cancelling are handled
                    by Stripe. Prices are in {currency}.
                </p>
            )}
        </div>
    );
}

export default function Billing({
    billing,
    lines,
    total_cents,
    currency,
    tracked,
}: Props) {
    const [saving, setSaving] = useState<string | null>(null);
    const money = (cents: number) => formatMoney(cents, currency);
    const inTrial = billing.status === 'trial' && !billing.subscribed;

    const toggle = (key: 'rentals' | 'time', on: boolean) => {
        if (
            !on &&
            !window.confirm(
                key === 'rentals'
                    ? 'Turn off Rentals? Your fleet and rental history are kept, but the rental screens and booking page are hidden until you turn it back on.'
                    : 'Turn off Time and attendance? Punches and timesheets are kept, but the time clock stops until you turn it back on.',
            )
        ) {
            return;
        }

        setSaving(key);
        router.put(
            BillingController.addOns.url(),
            { [key]: on },
            { preserveScroll: true, onFinish: () => setSaving(null) },
        );
    };

    return (
        <>
            <Head title="Billing" />
            <h1 className="sr-only">Billing</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Billing"
                    description={`Your YardRunner subscription. Prices are monthly, in ${currency}, before tax.`}
                />

                <Status
                    billing={billing}
                    total={money(total_cents)}
                    currency={currency}
                />

                {!billing.exempt && (
                    <section className="space-y-3">
                        <Heading
                            variant="small"
                            title="Plan and add-ons"
                            description={
                                inTrial
                                    ? 'Switch on any add-on to try it free until your trial ends.'
                                    : billing.subscribed
                                      ? 'Switching an add-on on or off adjusts your next bill for the part of the month it’s used.'
                                      : 'Choose what you need before adding your payment details.'
                            }
                        />
                        <ul className="divide-y divide-border rounded-md border border-border bg-card">
                            {lines.map((line) => (
                                <li
                                    key={line.key}
                                    className="flex items-start gap-4 p-4"
                                >
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                            <span className="font-medium">
                                                {line.name}
                                            </span>
                                            <span className="text-sm tabular-nums">
                                                {money(line.unit_cents)}
                                                {line.per_user
                                                    ? ' per user'
                                                    : ''}
                                                /mo
                                            </span>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {blurbs[line.key]}
                                        </p>
                                        {line.key === 'time' && line.on && (
                                            <p className="text-sm">
                                                {tracked === 1
                                                    ? '1 person tracked'
                                                    : `${tracked} people tracked`}{' '}
                                                ·{' '}
                                                <span className="tabular-nums">
                                                    {money(line.total_cents)}
                                                    /mo
                                                </span>
                                            </p>
                                        )}
                                    </div>
                                    {line.key === 'base' ? (
                                        <span className="mt-0.5 text-xs text-muted-foreground">
                                            Included
                                        </span>
                                    ) : (
                                        <Switch
                                            on={line.on}
                                            label={line.name}
                                            disabled={
                                                saving !== null ||
                                                billing.suspended
                                            }
                                            onChange={(on) =>
                                                toggle(
                                                    line.key as
                                                        | 'rentals'
                                                        | 'time',
                                                    on,
                                                )
                                            }
                                        />
                                    )}
                                </li>
                            ))}
                            <li className="flex items-baseline justify-between gap-3 p-4">
                                <span className="font-medium">
                                    {inTrial ? 'After your trial' : 'Total'}
                                </span>
                                <span className="font-medium tabular-nums">
                                    {money(total_cents)}/mo plus tax
                                </span>
                            </li>
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
