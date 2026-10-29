import { Head, Link, router } from '@inertiajs/react';
import { Camera, ChevronLeft, Delete, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import KioskController from '@/actions/App/Http/Controllers/Time/KioskController';
import { moveStorageKey } from '@/lib/storage';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { ClockStateValue, PunchKind } from '@/types/time';

type Person = {
    id: number;
    name: string;
    yard_id: number | null;
    has_pin: boolean;
    state: ClockStateValue;
    since_label: string | null;
    allowed: { type: PunchKind; label: string }[];
    needs_photo: boolean;
};

type Props = {
    people: Person[];
    yards: { id: number; name: string }[];
    business: string;
};

type Confirmation = {
    name: string;
    action: string;
    time: string;
    week: string;
    overtime: boolean;
};

const YARD_KEY = 'yardrunner.kiosk.yard';
const OLD_YARD_KEY = 'yardline.kiosk.yard';
const IDLE_MS = 30_000;

const stateText: Record<ClockStateValue, string> = {
    working: 'Working',
    on_break: 'On break',
    out: 'Out',
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p[0] ?? '')
        .join('')
        .toUpperCase();
}

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = window.setInterval(() => setNow(new Date()), 15_000);

        return () => window.clearInterval(id);
    }, []);

    return now;
}

/**
 * The front camera, for the photo some businesses take on each punch.
 */
function useCamera(on: boolean) {
    const video = useRef<HTMLVideoElement | null>(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        if (!on || !navigator.mediaDevices?.getUserMedia) {
            return;
        }

        let stream: MediaStream | null = null;
        let cancelled = false;

        navigator.mediaDevices
            .getUserMedia({ video: { facingMode: 'user', width: 480 } })
            .then((s) => {
                if (cancelled) {
                    s.getTracks().forEach((t) => t.stop());

                    return;
                }

                stream = s;

                if (video.current) {
                    video.current.srcObject = s;
                    void video.current.play();
                    setReady(true);
                }
            })
            .catch(() => setReady(false));

        return () => {
            cancelled = true;
            stream?.getTracks().forEach((t) => t.stop());
            setReady(false);
        };
    }, [on]);

    function capture(): string | null {
        const v = video.current;

        if (!ready || !v || v.videoWidth === 0) {
            return null;
        }

        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 480 / v.videoWidth);
        canvas.width = Math.round(v.videoWidth * scale);
        canvas.height = Math.round(v.videoHeight * scale);
        canvas
            .getContext('2d')
            ?.drawImage(v, 0, 0, canvas.width, canvas.height);

        return canvas.toDataURL('image/jpeg', 0.7);
    }

    return { video, ready, capture };
}

/**
 * The time clock on the counter tablet (screen 1 of the time mockups):
 * tap your name, pick what you're doing, enter your PIN.
 */
export default function TimeClockKiosk({ people, yards, business }: Props) {
    const now = useNow();
    const [yardId, setYardId] = useState<number | null>(() => {
        moveStorageKey(OLD_YARD_KEY, YARD_KEY);

        try {
            const saved = Number(window.localStorage.getItem(YARD_KEY));

            return yards.some((y) => y.id === saved)
                ? saved
                : (yards[0]?.id ?? null);
        } catch {
            return yards[0]?.id ?? null;
        }
    });
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<Person | null>(null);
    const [action, setAction] = useState<PunchKind | null>(null);
    const [pin, setPin] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState<Confirmation | null>(null);
    const camera = useCamera(Boolean(selected?.needs_photo && action));

    function reset() {
        setSelected(null);
        setAction(null);
        setPin('');
        setError(null);
        setBusy(false);
    }

    // Back to the name grid when nobody's touching it.
    useEffect(() => {
        if (!selected && !done) {
            return;
        }

        const id = window.setTimeout(
            () => {
                setSelected(null);
                setAction(null);
                setPin('');
                setError(null);
                setBusy(false);
                setDone(null);
            },
            done ? 5_000 : IDLE_MS,
        );

        return () => window.clearTimeout(id);
    }, [selected, action, pin, done]);

    // Keep who's in fresh for the next person.
    useEffect(() => {
        const id = window.setInterval(
            () => router.reload({ only: ['people'] }),
            60_000,
        );

        return () => window.clearInterval(id);
    }, []);

    const shown = useMemo(() => {
        const q = query.trim().toLowerCase();

        return people
            .filter((p) => q === '' || p.name.toLowerCase().includes(q))
            .sort(
                (a, b) =>
                    Number(b.yard_id === yardId) -
                        Number(a.yard_id === yardId) ||
                    a.name.localeCompare(b.name),
            );
    }, [people, query, yardId]);

    function submit(entered: string) {
        if (!selected || !action || busy) {
            return;
        }

        setBusy(true);
        setError(null);

        router.post(
            KioskController.punch.url(),
            {
                employee_id: selected.id,
                pin: entered,
                type: action,
                location_id: yardId,
                photo: selected.needs_photo ? camera.capture() : null,
            },
            {
                preserveScroll: true,
                onFlash: (flash) => {
                    const kiosk = (flash as { kiosk?: Confirmation }).kiosk;

                    if (kiosk) {
                        setDone(kiosk);
                        reset();
                    }
                },
                onError: (errors) => {
                    setError(
                        errors.pin ??
                            errors.time ??
                            Object.values(errors)[0] ??
                            'That didn’t work. Try again.',
                    );
                    setPin('');
                    setBusy(false);
                },
                onFinish: () => setBusy(false),
            },
        );
    }

    function press(key: string) {
        setError(null);

        if (key === 'back') {
            setPin((p) => p.slice(0, -1));

            return;
        }

        const next = (pin + key).slice(0, 6);
        setPin(next);
    }

    const time = now.toLocaleTimeString('en-CA', {
        hour: 'numeric',
        minute: '2-digit',
    });

    return (
        <>
            <Head title="Time clock" />
            <div className="min-h-svh bg-[#1c1917] px-4 py-5 text-[#fafaf9] md:px-8">
                <header className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link
                            href={dashboard()}
                            className="flex items-center gap-1 text-sm text-[#a8a29e]"
                        >
                            <ChevronLeft className="size-4" /> YardRunner
                        </Link>
                        <h1 className="text-2xl font-bold">Time clock</h1>
                        <p className="text-sm text-[#a8a29e]">{business}</p>
                    </div>
                    <div className="flex items-center gap-4">
                        {yards.length > 1 && (
                            <select
                                aria-label="This tablet is at"
                                value={String(yardId ?? '')}
                                onChange={(e) => {
                                    const id = Number(e.target.value);
                                    setYardId(id);

                                    try {
                                        window.localStorage.setItem(
                                            YARD_KEY,
                                            String(id),
                                        );
                                    } catch {
                                        // Not remembered; still used now.
                                    }
                                }}
                                className="h-11 rounded-lg border border-[#44403c] bg-[#292524] px-3 text-base"
                            >
                                {yards.map((y) => (
                                    <option key={y.id} value={y.id}>
                                        {y.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <div className="figure text-4xl font-bold">{time}</div>
                    </div>
                </header>

                <main className="mx-auto mt-6 max-w-5xl">
                    {done ? (
                        <div
                            role="status"
                            className="mx-auto max-w-md rounded-2xl bg-[#292524] p-8 text-center"
                        >
                            <div className="text-lg text-[#d6d3d1]">
                                {done.name}
                            </div>
                            <div className="mt-1 text-3xl font-bold">
                                {done.action} · {done.time}
                            </div>
                            <div className="mt-3 text-[#d6d3d1]">
                                {done.week}
                                {done.overtime && (
                                    <span className="ml-2 font-semibold text-[#fcd34d]">
                                        into overtime
                                    </span>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={() => setDone(null)}
                                className="mt-6 h-12 w-full rounded-xl bg-[#c2410c] text-lg font-bold text-white"
                            >
                                Done
                            </button>
                        </div>
                    ) : selected ? (
                        <div className="mx-auto grid max-w-md gap-4">
                            <button
                                type="button"
                                onClick={reset}
                                className="flex items-center gap-1 text-sm text-[#d6d3d1]"
                            >
                                <ChevronLeft className="size-4" /> Not you?
                            </button>
                            <div>
                                <div className="text-3xl font-bold">
                                    {selected.name}
                                </div>
                                <div className="text-[#d6d3d1]">
                                    {stateText[selected.state]}
                                    {selected.since_label
                                        ? ` since ${selected.since_label}`
                                        : ''}
                                </div>
                            </div>

                            {!action ? (
                                <div className="grid gap-3">
                                    {selected.allowed.map((a, i) => (
                                        <button
                                            key={a.type}
                                            type="button"
                                            onClick={() => setAction(a.type)}
                                            className={cn(
                                                'h-16 rounded-xl text-xl font-bold',
                                                i === 0
                                                    ? 'bg-[#c2410c] text-white'
                                                    : 'border border-[#44403c] bg-[#292524]',
                                            )}
                                        >
                                            {a.label}
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <div className="grid gap-4">
                                    <div className="text-center text-lg font-semibold">
                                        {
                                            selected.allowed.find(
                                                (a) => a.type === action,
                                            )?.label
                                        }
                                        : enter your PIN
                                    </div>

                                    {selected.needs_photo && (
                                        <div className="relative mx-auto aspect-[4/3] w-48 overflow-hidden rounded-xl bg-[#292524]">
                                            <video
                                                ref={camera.video}
                                                muted
                                                playsInline
                                                className="size-full -scale-x-100 object-cover"
                                            />
                                            {!camera.ready && (
                                                <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-xs text-[#a8a29e]">
                                                    <Camera className="size-5" />
                                                    Photo taken with your punch
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div
                                        className="figure flex h-14 items-center justify-center gap-3 rounded-xl bg-[#292524] text-3xl tracking-[0.5em]"
                                        aria-label={`${pin.length} digits entered`}
                                    >
                                        {pin.replace(/./g, '•') || (
                                            <span className="text-base tracking-normal text-[#78716c]">
                                                PIN
                                            </span>
                                        )}
                                    </div>
                                    {error && (
                                        <p
                                            role="alert"
                                            className="text-center font-semibold text-[#fca5a5]"
                                        >
                                            {error}
                                        </p>
                                    )}
                                    <div className="grid grid-cols-3 gap-3">
                                        {[
                                            '1',
                                            '2',
                                            '3',
                                            '4',
                                            '5',
                                            '6',
                                            '7',
                                            '8',
                                            '9',
                                        ].map((k) => (
                                            <button
                                                key={k}
                                                type="button"
                                                onClick={() => press(k)}
                                                className="figure h-16 rounded-xl bg-[#292524] text-2xl font-bold active:bg-[#44403c]"
                                            >
                                                {k}
                                            </button>
                                        ))}
                                        <button
                                            type="button"
                                            onClick={() => press('back')}
                                            aria-label="Delete"
                                            className="flex h-16 items-center justify-center rounded-xl bg-[#292524] active:bg-[#44403c]"
                                        >
                                            <Delete className="size-6" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => press('0')}
                                            className="figure h-16 rounded-xl bg-[#292524] text-2xl font-bold active:bg-[#44403c]"
                                        >
                                            0
                                        </button>
                                        <button
                                            type="button"
                                            disabled={pin.length < 4 || busy}
                                            onClick={() => submit(pin)}
                                            className="h-16 rounded-xl bg-[#c2410c] text-lg font-bold text-white disabled:bg-[#44403c] disabled:text-[#a8a29e]"
                                        >
                                            {busy ? '…' : 'OK'}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <>
                            {people.length > 12 && (
                                <label className="mb-4 flex h-12 items-center gap-2 rounded-xl bg-[#292524] px-4">
                                    <Search className="size-5 text-[#a8a29e]" />
                                    <input
                                        value={query}
                                        onChange={(e) =>
                                            setQuery(e.target.value)
                                        }
                                        placeholder="Find your name"
                                        className="w-full bg-transparent text-lg outline-none placeholder:text-[#78716c]"
                                    />
                                </label>
                            )}

                            {people.length === 0 ? (
                                <p className="rounded-2xl bg-[#292524] p-8 text-center text-[#d6d3d1]">
                                    Nobody is set up to clock in here yet. A
                                    manager can add people under Team →
                                    Employees.
                                </p>
                            ) : (
                                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    {shown.map((p) => (
                                        <li key={p.id}>
                                            <button
                                                type="button"
                                                disabled={!p.has_pin}
                                                onClick={() => setSelected(p)}
                                                className="flex h-full w-full items-center gap-3 rounded-2xl bg-[#292524] p-4 text-left active:bg-[#44403c] disabled:opacity-50"
                                            >
                                                <span
                                                    className={cn(
                                                        'flex size-12 shrink-0 items-center justify-center rounded-full text-lg font-bold',
                                                        p.state === 'working'
                                                            ? 'bg-[#166534] text-white'
                                                            : p.state ===
                                                                'on_break'
                                                              ? 'bg-[#a16207] text-white'
                                                              : 'bg-[#44403c] text-[#d6d3d1]',
                                                    )}
                                                >
                                                    {initials(p.name)}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block truncate text-lg font-semibold">
                                                        {p.name}
                                                    </span>
                                                    <span className="block truncate text-sm text-[#a8a29e]">
                                                        {p.has_pin
                                                            ? `${stateText[p.state]}${p.since_label ? ` · ${p.since_label}` : ''}`
                                                            : 'No PIN yet: ask a manager'}
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                </main>
            </div>
        </>
    );
}
