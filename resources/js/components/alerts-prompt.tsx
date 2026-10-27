import { Link, usePage } from '@inertiajs/react';
import { BellRing, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import AlertsController from '@/actions/App/Http/Controllers/Settings/AlertsController';
import { Button } from '@/components/ui/button';
import {
    currentSubscription,
    PushError,
    pushProblem,
    subscribe,
    syncSubscription,
} from '@/lib/push';
import { cn } from '@/lib/utils';

const DISMISSED_KEY = 'yardrunner:alerts-prompt-dismissed';

function dismissed(): boolean {
    try {
        return localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return true;
    }
}

/**
 * Keeps this device's alerts current, and asks once (until dismissed)
 * whether to turn them on, for people whose roles get alerts: yard crew,
 * drivers, checkers, the rental desk.
 */
export function AlertsPrompt({ className }: { className?: string }) {
    const { auth, push } = usePage().props;
    const [show, setShow] = useState(false);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const userId = auth.user?.id;

    useEffect(() => {
        if (!push || !userId) {
            return;
        }

        void syncSubscription(push.key, userId).catch(() => undefined);

        if (!push.alerts || dismissed()) {
            return;
        }

        const why = pushProblem();

        if (why === 'unsupported' || why === 'blocked') {
            return;
        }

        if (why === 'needs-install') {
            setShow(true);

            return;
        }

        if (Notification.permission !== 'default') {
            return;
        }

        void currentSubscription().then((subscription) =>
            setShow(subscription === null),
        );
    }, [push, userId]);

    if (!show || !push) {
        return null;
    }

    const needsInstall = pushProblem() === 'needs-install';

    const close = () => {
        try {
            localStorage.setItem(DISMISSED_KEY, '1');
        } catch {
            // Storage blocked: it'll ask again next time.
        }

        setShow(false);
    };

    const turnOn = async () => {
        setBusy(true);
        setProblem(null);

        try {
            await subscribe(push.key);
            setShow(false);
        } catch (error) {
            const reason =
                error instanceof PushError ? error.problem : 'failed';

            if (reason === 'dismissed' || reason === 'blocked') {
                close();
            } else {
                setProblem(
                    'Couldn’t turn alerts on. Try again from Settings → Alerts.',
                );
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <div
            className={cn(
                'flex items-start gap-3 rounded-lg border border-border bg-card p-3 text-sm',
                className,
            )}
        >
            <BellRing
                className="mt-0.5 size-5 shrink-0 text-primary"
                aria-hidden
            />
            <div className="min-w-0 flex-1">
                <p className="font-medium">
                    {needsInstall
                        ? 'Add YardRunner to your home screen for alerts'
                        : 'Get alerts on this device'}
                </p>
                <p className="mt-0.5 text-muted-foreground">
                    {needsInstall ? (
                        <>
                            On iPhone and iPad, alerts only work from the
                            installed app.{' '}
                            <Link
                                href={AlertsController.edit()}
                                className="font-medium text-foreground underline underline-offset-2"
                            >
                                How to add it
                            </Link>
                        </>
                    ) : (
                        'New work for you shows up even when YardRunner isn’t open.'
                    )}
                </p>
                {problem && <p className="mt-1 text-destructive">{problem}</p>}
                {!needsInstall && (
                    <Button
                        size="sm"
                        className="mt-2"
                        disabled={busy}
                        onClick={() => void turnOn()}
                    >
                        Turn on alerts
                    </Button>
                )}
            </div>
            <button
                type="button"
                onClick={close}
                className="-m-1 rounded-md p-1 text-muted-foreground hover:text-foreground"
                aria-label="Not now"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
