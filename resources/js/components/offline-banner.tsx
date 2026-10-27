import { WifiOff } from 'lucide-react';
import { useSyncExternalStore } from 'react';

function subscribe(callback: () => void): () => void {
    window.addEventListener('online', callback);
    window.addEventListener('offline', callback);

    return () => {
        window.removeEventListener('online', callback);
        window.removeEventListener('offline', callback);
    };
}

/**
 * A strip along the bottom while the device has no connection, so nobody
 * wonders why a tap did nothing out in the yard.
 */
export function OfflineBanner() {
    const online = useSyncExternalStore(
        subscribe,
        () => navigator.onLine,
        () => true,
    );

    if (online) {
        return null;
    }

    return (
        <div
            role="status"
            className="fixed inset-x-0 bottom-0 z-50 flex items-center justify-center gap-2 border-t border-warning/20 bg-warning-soft px-4 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] text-center text-sm font-medium text-warning"
        >
            <WifiOff className="size-4 shrink-0" aria-hidden />
            No connection. Changes won’t save until you’re back online.
        </div>
    );
}
