import { Head, router } from '@inertiajs/react';
import { BellOff, BellRing, Check, Download, Share } from 'lucide-react';
import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import AlertsController from '@/actions/App/Http/Controllers/Settings/AlertsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { formatAgo } from '@/lib/format';
import {
    currentSubscription,
    isIos,
    isStandalone,
    PushError,
    pushProblem,
    subscribe,
    unsubscribe,
} from '@/lib/push';
import type { PushProblem } from '@/lib/push';
import { installState, promptInstall, subscribeToInstall } from '@/lib/pwa';

type Device = {
    id: number;
    device: string;
    endpoint_hash: string;
    last_used_at: string | null;
    created_at: string | null;
};

type Props = {
    publicKey: string | null;
    demo: boolean;
    alerts: string[];
    devices: Device[];
};

type State = 'checking' | 'on' | 'off' | PushProblem;

async function sha256(text: string): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(text),
    );

    return Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

const PROBLEMS: Partial<Record<State, string>> = {
    unsupported:
        'This browser can’t show alerts. Try Chrome, Edge, Firefox or Safari.',
    'needs-install':
        'On iPhone and iPad, alerts only work from YardRunner on your home screen. Add it below, open it from there, then come back here.',
    blocked:
        'Alerts are blocked for YardRunner in this browser’s settings. Allow notifications for this site, then reload.',
    dismissed: 'No alerts yet: the browser asked and nothing was allowed.',
    failed: 'Couldn’t turn alerts on. Check the connection and try again.',
};

function AlertsCard({ publicKey, demo, alerts }: Props) {
    const [state, setState] = useState<State>('checking');
    const [busy, setBusy] = useState(false);

    const refresh = useCallback(async () => {
        const problem = pushProblem();

        if (problem !== null) {
            setState(problem);

            return;
        }

        setState((await currentSubscription()) ? 'on' : 'off');
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    if (demo) {
        return (
            <p className="text-sm text-muted-foreground">
                Alerts are off in the demo yard, since everyone trying it shares
                the same people.
            </p>
        );
    }

    if (!publicKey) {
        return (
            <p className="text-sm text-muted-foreground">
                Alerts aren’t set up on this server yet. Ask whoever looks after
                YardRunner to add the push keys.
            </p>
        );
    }

    const turnOn = async () => {
        setBusy(true);

        try {
            await subscribe(publicKey);
            setState('on');
            router.reload({ only: ['devices'] });
        } catch (error) {
            setState(error instanceof PushError ? error.problem : 'failed');
        } finally {
            setBusy(false);
        }
    };

    const turnOff = async () => {
        setBusy(true);

        try {
            await unsubscribe();
            setState('off');
            router.reload({ only: ['devices'] });
        } finally {
            setBusy(false);
        }
    };

    const on = state === 'on';
    const message = PROBLEMS[state];

    return (
        <div className="space-y-4">
            <div className="flex items-start gap-3 rounded-lg border border-border p-4">
                {on ? (
                    <BellRing
                        className="mt-0.5 size-5 shrink-0 text-success"
                        aria-hidden
                    />
                ) : (
                    <BellOff
                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                )}
                <div className="min-w-0 flex-1 space-y-3">
                    <div>
                        <p className="text-sm font-medium">
                            {state === 'checking'
                                ? 'Checking this device…'
                                : on
                                  ? 'Alerts are on for this device'
                                  : 'Alerts are off for this device'}
                        </p>
                        {message && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                {message}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {on ? (
                            <>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        router.post(
                                            AlertsController.test.url(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Send a test alert
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => void turnOff()}
                                >
                                    Turn off here
                                </Button>
                            </>
                        ) : (
                            ![
                                'checking',
                                'unsupported',
                                'needs-install',
                                'blocked',
                            ].includes(state) && (
                                <Button
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => void turnOn()}
                                >
                                    Turn on alerts
                                </Button>
                            )
                        )}
                    </div>
                </div>
            </div>

            <div>
                <p className="text-sm font-medium">You get an alert when</p>
                {alerts.length === 0 ? (
                    <p className="mt-1 text-sm text-muted-foreground">
                        Nothing yet: alerts go to yard crew, drivers, the rental
                        desk and whoever signs off quality checks.
                    </p>
                ) : (
                    <ul className="mt-2 space-y-1.5">
                        {alerts.map((alert) => (
                            <li key={alert} className="flex gap-2 text-sm">
                                <Check
                                    className="mt-0.5 size-4 shrink-0 text-success"
                                    aria-hidden
                                />
                                {alert}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function InstallCard() {
    const install = useSyncExternalStore(
        subscribeToInstall,
        installState,
        () => 'unavailable' as const,
    );
    const standalone = isStandalone();

    if (standalone || install === 'installed') {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Check className="size-4 text-success" aria-hidden />
                You’re using the installed app.
            </p>
        );
    }

    if (install === 'available') {
        return (
            <div className="space-y-3">
                <p className="text-sm text-muted-foreground">
                    Opens full screen from its own icon, like any other app.
                    Handy on the counter tablet, the yard tablet and drivers’
                    phones.
                </p>
                <Button size="sm" onClick={() => void promptInstall()}>
                    <Download className="size-4" aria-hidden />
                    Install YardRunner
                </Button>
            </div>
        );
    }

    if (isIos()) {
        return (
            <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                <li>
                    In Safari, tap{' '}
                    <Share
                        className="inline size-4 align-text-bottom"
                        aria-label="Share"
                    />{' '}
                    Share.
                </li>
                <li>Choose “Add to Home Screen”, then Add.</li>
                <li>Open YardRunner from your home screen and sign in.</li>
            </ol>
        );
    }

    return (
        <p className="text-sm text-muted-foreground">
            Use your browser’s menu and choose “Install app” or “Add to Home
            screen”. In Chrome and Edge it’s also the install icon at the end of
            the address bar.
        </p>
    );
}

function Devices({ devices }: { devices: Device[] }) {
    const [mine, setMine] = useState<string | null>(null);

    useEffect(() => {
        void currentSubscription().then(async (subscription) => {
            if (subscription && window.isSecureContext) {
                setMine(await sha256(subscription.endpoint));
            }
        });
    }, [devices]);

    if (devices.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No devices get alerts yet.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-border rounded-lg border border-border">
            {devices.map((device) => (
                <li
                    key={device.id}
                    className="flex items-center gap-3 px-4 py-3"
                >
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {device.device}
                            {device.endpoint_hash === mine && (
                                <span className="ml-2 text-xs font-normal text-muted-foreground">
                                    This device
                                </span>
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Last alert {formatAgo(device.last_used_at, 'never')}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.delete(AlertsController.destroy.url(), {
                                data: { id: device.id },
                                preserveScroll: true,
                            })
                        }
                    >
                        Remove
                    </Button>
                </li>
            ))}
        </ul>
    );
}

export default function Alerts(props: Props) {
    return (
        <>
            <Head title="Alerts" />

            <h1 className="sr-only">Alerts</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Alerts"
                    description="Alerts on this phone or tablet for new work, even when YardRunner isn’t open"
                />
                <AlertsCard {...props} />

                <Separator />

                <Heading
                    variant="small"
                    title="Install the app"
                    description="Put YardRunner on the home screen of this device"
                />
                <InstallCard />

                <Separator />

                <Heading
                    variant="small"
                    title="Your devices"
                    description="Everywhere you get alerts. Signing in on a shared tablet moves its alerts to you."
                />
                <Devices devices={props.devices} />
            </div>
        </>
    );
}

Alerts.layout = {
    breadcrumbs: [
        {
            title: 'Alerts',
            href: AlertsController.edit(),
        },
    ],
};
