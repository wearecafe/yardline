import { Head, router } from '@inertiajs/react';
import type { StripeConnectInstance } from '@stripe/connect-js';
import {
    ConnectAccountManagement,
    ConnectAccountOnboarding,
    ConnectComponentsProvider,
    ConnectDocuments,
    ConnectNotificationBanner,
    ConnectPayments,
    ConnectPayouts,
    ConnectTaxRegistrations,
    ConnectTaxSettings,
} from '@stripe/react-connect-js';
import { useEffect, useState } from 'react';
import PaymentsController from '@/actions/App/Http/Controllers/Settings/PaymentsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { Tabs } from '@/components/yard/tabs';
import { useAppearance } from '@/hooks/use-appearance';
import { postJson } from '@/lib/json';
import { formatMoney } from '@/lib/format';

type Props = {
    status: {
        state:
            | 'not_started'
            | 'onboarding'
            | 'action_required'
            | 'paused'
            | 'active';
        label: string;
        tone: ChipTone;
        summary: string;
        due: string[];
    };
    has_account: boolean;
    charges_enabled: boolean;
    payouts_enabled: boolean;
    details_submitted: boolean;
    simulated: boolean;
    demo: boolean;
    publishable_key: string | null;
    fee: { percent: number; fixed_cents: number };
};

export default function Payments(props: Props) {
    const { status, has_account, simulated, demo, fee } = props;
    const [busy, setBusy] = useState(false);

    function start() {
        setBusy(true);
        router.post(
            PaymentsController.store.url(),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    const feeText = [
        fee.percent > 0 ? `${fee.percent}%` : null,
        fee.fixed_cents > 0 ? formatMoney(fee.fixed_cents) : null,
    ]
        .filter(Boolean)
        .join(' + ');

    return (
        <>
            <Head title="Payments" />
            <h1 className="sr-only">Payments</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Payments"
                    description="Take cards at the counter, on payment links and for rentals. Payouts go to your bank account."
                />

                <div className="space-y-2 rounded-lg border bg-card p-4">
                    <div className="flex items-center justify-between gap-3">
                        <span className="font-medium">Card payments</span>
                        <Chip tone={status.tone}>{status.label}</Chip>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {status.summary}
                    </p>
                    {status.due.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {status.due.length === 1
                                ? '1 detail is needed.'
                                : `${status.due.length} details are needed.`}
                        </p>
                    )}
                    {feeText !== '' && (
                        <p className="text-sm text-muted-foreground">
                            YardRunner fee: {feeText} per card payment, on top
                            of card processing fees. Both come off the payment
                            before it’s paid out.
                        </p>
                    )}
                </div>

                {demo ? (
                    <p className="text-sm text-muted-foreground">
                        The demo yard takes simulated card payments, so there’s
                        nothing to set up here.
                    </p>
                ) : !has_account ? (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            You’ll enter your business details and the bank
                            account for payouts. It takes about ten minutes.
                        </p>
                        <Button onClick={start} disabled={busy}>
                            Set up card payments
                        </Button>
                    </div>
                ) : simulated ? (
                    <Simulated {...props} />
                ) : (
                    <Embedded {...props} />
                )}
            </div>
        </>
    );
}

/**
 * Local development: no Stripe, so onboarding is a button.
 */
function Simulated({ charges_enabled }: Props) {
    if (charges_enabled) {
        return (
            <p className="text-sm text-muted-foreground">
                Card payments are simulated in local development. Payment links
                mark the payment as paid when opened.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
                There’s no Stripe in local development. Finish setup to switch
                simulated card payments on.
            </p>
            <Button
                variant="outline"
                onClick={() =>
                    router.post(
                        PaymentsController.simulate.url(),
                        {},
                        { preserveScroll: true },
                    )
                }
            >
                Finish setup (simulated)
            </Button>
        </div>
    );
}

/**
 * Stripe's embedded components, styled to match YardRunner. The yard never
 * signs in to Stripe; everything happens here.
 */
function Embedded({ publishable_key, details_submitted }: Props) {
    const { resolvedAppearance } = useAppearance();
    const [connect, setConnect] = useState<StripeConnectInstance | null>(null);

    useEffect(() => {
        if (!publishable_key) {
            return;
        }

        let cancelled = false;

        // Loaded here, not at the top, so Stripe's script only loads on
        // this page.
        void import('@stripe/connect-js').then(
            ({ loadConnectAndInitialize }) => {
                if (cancelled) {
                    return;
                }

                setConnect(
                    loadConnectAndInitialize({
                        publishableKey: publishable_key,
                        fetchClientSecret: fetchSecret,
                        appearance: { variables: themeVariables() },
                        locale: document.documentElement.lang || 'en-CA',
                    }),
                );
            },
        );

        return () => {
            cancelled = true;
        };
    }, [publishable_key]);

    // Follow light and dark mode.
    useEffect(() => {
        connect?.update({ appearance: { variables: themeVariables() } });
    }, [connect, resolvedAppearance]);

    if (!publishable_key) {
        return (
            <p className="text-sm text-danger">
                Stripe isn’t configured (STRIPE_KEY is missing).
            </p>
        );
    }

    if (!connect) {
        return <p className="text-sm text-muted-foreground">Loading…</p>;
    }

    const refresh = () =>
        router.post(
            PaymentsController.refresh.url(),
            {},
            { preserveScroll: true },
        );

    return (
        <ConnectComponentsProvider connectInstance={connect}>
            <div className="space-y-6">
                <ConnectNotificationBanner />

                {!details_submitted ? (
                    <ConnectAccountOnboarding onExit={refresh} />
                ) : (
                    <Tabs
                        label="Payments"
                        items={[
                            {
                                value: 'payouts',
                                label: 'Payouts',
                                content: <ConnectPayouts />,
                            },
                            {
                                value: 'payments',
                                label: 'Payments & disputes',
                                content: <ConnectPayments />,
                            },
                            {
                                value: 'account',
                                label: 'Business & bank',
                                content: <ConnectAccountManagement />,
                            },
                            {
                                value: 'tax',
                                label: 'Sales tax',
                                content: (
                                    <div className="space-y-8">
                                        <ConnectTaxSettings />
                                        <ConnectTaxRegistrations />
                                    </div>
                                ),
                            },
                            {
                                value: 'documents',
                                label: 'Documents',
                                content: <ConnectDocuments />,
                            },
                        ]}
                    />
                )}
            </div>
        </ConnectComponentsProvider>
    );
}

async function fetchSecret(): Promise<string> {
    const response = await postJson(PaymentsController.session.url(), {});

    if (!response.ok) {
        throw new Error('Could not start a Stripe session.');
    }

    const data = (await response.json()) as { client_secret: string };

    return data.client_secret;
}

/** YardRunner's colours and type, read from the page's CSS variables. */
function themeVariables() {
    const css = getComputedStyle(document.documentElement);
    const read = (name: string) =>
        css.getPropertyValue(name).trim() || undefined;

    return {
        colorPrimary: read('--primary'),
        colorBackground: read('--card'),
        colorText: read('--foreground'),
        colorSecondaryText: read('--muted-foreground'),
        colorBorder: read('--border'),
        fontFamily: read('--font-sans'),
        borderRadius: '10px',
    };
}

Payments.layout = {
    breadcrumbs: [{ title: 'Payments', href: PaymentsController.edit() }],
};
