import { toast } from 'sonner';

/**
 * The installed app: registers the service worker, offers a reload when a
 * new version is ready, and keeps the browser's install prompt for the
 * Install button (Settings → Alerts).
 */

type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

let installPrompt: InstallPromptEvent | null = null;
let installed = false;
/** Set when someone taps Reload, so the new worker taking over reloads. */
let reloadOnTakeover = false;
const listeners = new Set<() => void>();

function changed(): void {
    listeners.forEach((listener) => listener());
}

export function subscribeToInstall(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function installState(): 'available' | 'installed' | 'unavailable' {
    if (installed) {
        return 'installed';
    }

    return installPrompt ? 'available' : 'unavailable';
}

/** Shows the browser's install dialog. Resolves true if they installed. */
export async function promptInstall(): Promise<boolean> {
    if (!installPrompt) {
        return false;
    }

    const event = installPrompt;
    installPrompt = null;
    await event.prompt();
    const { outcome } = await event.userChoice;
    changed();

    return outcome === 'accepted';
}

function offerUpdate(worker: ServiceWorker): void {
    toast('A new version of YardRunner is ready.', {
        id: 'app-update',
        duration: Infinity,
        action: {
            label: 'Reload',
            onClick: () => {
                reloadOnTakeover = true;
                worker.postMessage('skip-waiting');
            },
        },
    });
}

function watchForUpdates(registration: ServiceWorkerRegistration): void {
    if (registration.waiting && navigator.serviceWorker.controller) {
        offerUpdate(registration.waiting);
    }

    registration.addEventListener('updatefound', () => {
        const worker = registration.installing;

        worker?.addEventListener('statechange', () => {
            // A first install has no controller: nothing to replace yet.
            if (
                worker.state === 'installed' &&
                navigator.serviceWorker.controller
            ) {
                offerUpdate(worker);
            }
        });
    });

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloadOnTakeover) {
            reloadOnTakeover = false;
            window.location.reload();
        }
    });

    // The installed app can stay open for days on a yard tablet: look for
    // a new version whenever it comes back to the front.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            registration.update().catch(() => undefined);
        }
    });
}

export function initializePwa(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        // Keep it for our own Install button instead of the mini-infobar.
        event.preventDefault();
        installPrompt = event as InstallPromptEvent;
        changed();
    });

    window.addEventListener('appinstalled', () => {
        installed = true;
        installPrompt = null;
        changed();
    });

    // In development Vite serves changing files; a caching worker would
    // only get in the way.
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then(watchForUpdates)
            .catch(() => undefined);
    });
}
