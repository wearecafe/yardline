import { router } from '@inertiajs/react';
import { Clock, Coffee, LogIn, LogOut, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import MyTimeController from '@/actions/App/Http/Controllers/Time/MyTimeController';
import { postJson } from '@/lib/json';
import { cn } from '@/lib/utils';
import type { ClockInfo, PunchKind } from '@/types/time';

type QueuedPunch = {
    type: PunchKind;
    device_at: string;
    latitude?: number;
    longitude?: number;
    accuracy?: number;
};

const QUEUE_KEY = 'yardline.time.queue';

function readQueue(): QueuedPunch[] {
    try {
        const raw = window.localStorage.getItem(QUEUE_KEY);

        return raw ? (JSON.parse(raw) as QueuedPunch[]) : [];
    } catch {
        return [];
    }
}

function writeQueue(queue: QueuedPunch[]): void {
    try {
        if (queue.length === 0) {
            window.localStorage.removeItem(QUEUE_KEY);
        } else {
            window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
        }
    } catch {
        // Private mode: the punch can't wait, so it's lost; say so below.
    }
}

/** "2h 05m" since an ISO time, ticking. */
function useElapsed(since: string | null): string | null {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!since) {
            return;
        }

        const id = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(id);
    }, [since]);

    if (!since) {
        return null;
    }

    const minutes = Math.max(
        0,
        Math.floor((now - new Date(since).getTime()) / 60_000),
    );

    return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;
}

/** The phone's position, if it gives one quickly. */
function position(): Promise<GeolocationCoordinates | null> {
    if (!('geolocation' in navigator)) {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (p) => resolve(p.coords),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 8_000, maximumAge: 60_000 },
        );
    });
}

const labels: Record<PunchKind, string> = {
    in: 'Clock in',
    break_start: 'Start break',
    break_end: 'End break',
    out: 'Clock out',
};

const after: Record<PunchKind, ClockInfo['state']> = {
    in: 'working',
    break_start: 'on_break',
    break_end: 'working',
    out: 'out',
};

const allowedIn: Record<ClockInfo['state'], PunchKind[]> = {
    out: ['in'],
    working: ['break_start', 'out'],
    on_break: ['break_end', 'out'],
};

const stateLabels: Record<ClockInfo['state'], string> = {
    out: 'Clocked out',
    working: 'Working',
    on_break: 'On break',
};

const icons: Record<PunchKind, typeof LogIn> = {
    in: LogIn,
    break_start: Coffee,
    break_end: Clock,
    out: LogOut,
};

/**
 * The big clock button on someone's own phone: where they are in their
 * day, how long, and the punches that fit. Punches made with no signal
 * wait on the phone and go when it's back online, with the time they
 * were made.
 */
export function TimeClockCard({
    clock,
    dark = false,
}: {
    clock: ClockInfo;
    dark?: boolean;
}) {
    const [busy, setBusy] = useState<PunchKind | null>(null);
    const [queued, setQueued] = useState<QueuedPunch[]>(() => readQueue());
    const elapsed = useElapsed(clock.since);

    const flush = useCallback(async () => {
        const queue = readQueue();

        if (queue.length === 0 || !navigator.onLine) {
            return;
        }

        const left: QueuedPunch[] = [];

        for (const punch of queue) {
            try {
                const response = await postJson(
                    MyTimeController.punch.url(),
                    punch,
                );

                if (response.status === 422) {
                    const body = (await response.json().catch(() => null)) as {
                        message?: string;
                    } | null;
                    toast.error(
                        `A punch saved offline wasn’t accepted: ${body?.message ?? 'it no longer fits'}`,
                    );
                } else if (!response.ok && !response.redirected) {
                    left.push(punch);
                }
            } catch {
                left.push(punch);
            }
        }

        writeQueue(left);
        setQueued(left);

        if (left.length < queue.length) {
            toast.success('Punches saved offline have been sent.');
            router.reload();
        }
    }, []);

    useEffect(() => {
        void flush();
        window.addEventListener('online', flush);

        return () => window.removeEventListener('online', flush);
    }, [flush]);

    async function punch(type: PunchKind) {
        setBusy(type);
        const coords = clock.wants_location ? await position() : null;
        const data: QueuedPunch = {
            type,
            device_at: new Date().toISOString(),
            ...(coords
                ? {
                      latitude: coords.latitude,
                      longitude: coords.longitude,
                      accuracy: coords.accuracy,
                  }
                : {}),
        };

        router.post(
            MyTimeController.punch.url(),
            {
                type: data.type,
                latitude: data.latitude ?? null,
                longitude: data.longitude ?? null,
                accuracy: data.accuracy ?? null,
            },
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
                onNetworkError: () => {
                    const queue = [...readQueue(), data];
                    writeQueue(queue);
                    setQueued(queue);
                    toast.warning(
                        'No signal. Your punch is saved on this phone and will be sent with the time you made it.',
                    );

                    return false;
                },
            },
        );
    }

    // With punches waiting for signal, show the day as the phone sees it.
    const lastQueued = queued.length > 0 ? queued[queued.length - 1] : null;
    const state = lastQueued ? after[lastQueued.type] : clock.state;
    const allowed = lastQueued
        ? allowedIn[state].map((type) => ({ type, label: labels[type] }))
        : clock.allowed;
    const sinceLabel = lastQueued
        ? new Date(lastQueued.device_at).toLocaleTimeString('en-CA', {
              hour: 'numeric',
              minute: '2-digit',
          })
        : clock.since_label;

    const tone = {
        working: 'bg-success-soft text-success',
        on_break: 'bg-warning-soft text-warning',
        out: dark ? 'bg-[#292524] text-[#d6d3d1]' : 'bg-secondary text-subtle',
    }[state];

    return (
        <section
            className={cn(
                'rounded-[10px] border p-5',
                dark
                    ? 'border-[#44403c] bg-[#1c1917] text-[#fafaf9]'
                    : 'border-border bg-card',
            )}
            aria-label="Time clock"
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <span
                        className={cn(
                            'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold',
                            tone,
                        )}
                    >
                        {lastQueued ? stateLabels[state] : clock.state_label}
                        {sinceLabel && state !== 'out'
                            ? ` since ${sinceLabel}`
                            : ''}
                    </span>
                    {elapsed && !lastQueued && (
                        <div className="figure mt-2 text-4xl leading-none font-bold">
                            {elapsed}
                        </div>
                    )}
                </div>
                <div className="text-right text-sm">
                    <div
                        className={
                            dark ? 'text-[#a8a29e]' : 'text-muted-foreground'
                        }
                    >
                        This week
                    </div>
                    <div className="figure text-lg font-semibold">
                        {clock.week_label}
                    </div>
                </div>
            </div>

            {clock.missed_out && (
                <p
                    className={cn(
                        'mt-3 rounded-lg px-3 py-2 text-sm',
                        dark
                            ? 'bg-[#292524] text-[#fcd34d]'
                            : 'bg-warning-soft text-warning',
                    )}
                >
                    You didn’t clock out after {clock.missed_out}. Clock in as
                    usual; your manager will fix that day.
                </p>
            )}

            <div
                className={cn(
                    'mt-4 grid gap-3',
                    allowed.length > 1 && 'grid-cols-2',
                )}
            >
                {allowed.map((action, i) => {
                    const Icon = icons[action.type];
                    const primary =
                        action.type === 'in' ||
                        (i === 0 && action.type !== 'out');

                    return (
                        <button
                            key={action.type}
                            type="button"
                            disabled={busy !== null}
                            onClick={() => void punch(action.type)}
                            className={cn(
                                'flex h-16 items-center justify-center gap-2 rounded-xl text-lg font-bold transition-opacity disabled:opacity-60',
                                primary
                                    ? 'bg-primary text-primary-foreground'
                                    : dark
                                      ? 'border border-[#44403c] bg-[#292524] text-[#fafaf9]'
                                      : 'border border-input bg-card',
                            )}
                        >
                            <Icon className="size-5" />
                            {busy === action.type ? 'Saving…' : action.label}
                        </button>
                    );
                })}
            </div>

            {queued.length > 0 && (
                <p
                    className={cn(
                        'mt-3 flex items-center gap-2 text-sm',
                        dark ? 'text-[#fcd34d]' : 'text-warning',
                    )}
                >
                    <WifiOff className="size-4" />
                    {queued.length === 1
                        ? '1 punch waiting for signal'
                        : `${queued.length} punches waiting for signal`}
                </p>
            )}
        </section>
    );
}
