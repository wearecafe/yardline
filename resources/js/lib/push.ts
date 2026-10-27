import AlertsController from '@/actions/App/Http/Controllers/Settings/AlertsController';
import { sendJson } from '@/lib/json';

/**
 * Web push alerts for this device: ask permission, subscribe with the
 * server's key, and keep the server's record of this device current.
 */

export type PushProblem =
    | 'unsupported'
    | 'needs-install'
    | 'blocked'
    | 'dismissed'
    | 'failed';

export class PushError extends Error {
    constructor(public readonly problem: PushProblem) {
        super(problem);
    }
}

/** Remembered when someone turns alerts off here, so a sync won't undo it. */
const OFF_KEY = 'yardrunner:alerts-off';
const SYNCED_KEY = 'yardrunner:alerts-synced';

export function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

export function isIos(): boolean {
    return (
        /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        // iPadOS reports itself as a Mac with a touch screen.
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

/** Why alerts can't be turned on here, or null if they can. */
export function pushProblem(): PushProblem | null {
    // iPhone and iPad only allow web push from the installed app.
    if (isIos() && !isStandalone()) {
        return 'needs-install';
    }

    if (
        !('serviceWorker' in navigator) ||
        !('PushManager' in window) ||
        !('Notification' in window)
    ) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    return null;
}

async function registration(): Promise<ServiceWorkerRegistration> {
    const existing = await navigator.serviceWorker.getRegistration('/');

    if (!existing) {
        await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    }

    return navigator.serviceWorker.ready;
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    if (pushProblem() === 'unsupported') {
        return null;
    }

    const reg = await navigator.serviceWorker.getRegistration('/');

    return reg ? reg.pushManager.getSubscription() : null;
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const padded = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = window.atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/** Whether a subscription was made with this server key. */
function sameKey(subscription: PushSubscription, publicKey: string): boolean {
    const current = subscription.options.applicationServerKey;

    if (!current) {
        return false;
    }

    const a = new Uint8Array(current);
    const b = keyBytes(publicKey);

    return a.length === b.length && a.every((byte, i) => byte === b[i]);
}

async function save(subscription: PushSubscription): Promise<void> {
    const json = subscription.toJSON();
    const encodings =
        (PushManager as unknown as { supportedContentEncodings?: string[] })
            .supportedContentEncodings ?? [];

    const response = await sendJson('POST', AlertsController.store.url(), {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: encodings.includes('aes128gcm')
            ? 'aes128gcm'
            : (encodings[0] ?? 'aes128gcm'),
    });

    if (!response.ok) {
        throw new PushError('failed');
    }
}

/**
 * Turn alerts on for this device. Asks permission the first time.
 */
export async function subscribe(publicKey: string): Promise<PushSubscription> {
    const problem = pushProblem();

    if (problem !== null) {
        throw new PushError(problem);
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new PushError(permission === 'denied' ? 'blocked' : 'dismissed');
    }

    const reg = await registration();
    let subscription = await reg.pushManager.getSubscription();

    if (subscription && !sameKey(subscription, publicKey)) {
        await subscription.unsubscribe();
        subscription = null;
    }

    subscription ??= await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: keyBytes(publicKey),
    });

    await save(subscription);

    try {
        localStorage.removeItem(OFF_KEY);
    } catch {
        // Storage blocked: nothing to forget.
    }

    return subscription;
}

/**
 * Turn alerts off for this device.
 */
export async function unsubscribe(): Promise<void> {
    const subscription = await currentSubscription();

    try {
        localStorage.setItem(OFF_KEY, '1');
    } catch {
        // Storage blocked: the server forgets the device either way.
    }

    if (!subscription) {
        return;
    }

    await sendJson('DELETE', AlertsController.destroy.url(), {
        endpoint: subscription.endpoint,
    });
    await subscription.unsubscribe();
}

/**
 * Once per session and person: tell the server this device still wants
 * alerts (and who's signed in on it now), and move it to a new server key if the key
 * changed. Never asks for permission and never turns alerts on by itself.
 */
export async function syncSubscription(
    publicKey: string | null,
    userId: number,
): Promise<void> {
    if (!publicKey || pushProblem() !== null) {
        return;
    }

    // A shared tablet changes hands: the device follows whoever's signed in.
    const marker = `${userId}:${publicKey}`;

    try {
        if (
            sessionStorage.getItem(SYNCED_KEY) === marker ||
            localStorage.getItem(OFF_KEY) === '1'
        ) {
            return;
        }
    } catch {
        return;
    }

    if (Notification.permission !== 'granted') {
        return;
    }

    let subscription = await currentSubscription();

    if (!subscription) {
        return;
    }

    if (!sameKey(subscription, publicKey)) {
        await subscription.unsubscribe();
        const reg = await registration();
        subscription = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyBytes(publicKey),
        });
    }

    await save(subscription);

    try {
        sessionStorage.setItem(SYNCED_KEY, marker);
    } catch {
        // Storage blocked: it syncs again next page load, which is harmless.
    }
}
