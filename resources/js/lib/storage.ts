/**
 * Moves a value saved under an old localStorage key to its new key, once.
 * Used when a key is renamed, so a device keeps what it saved (queued time
 * punches, the kiosk's yard) across the deploy. Leaves an existing value
 * under the new key alone. Never throws (private mode, blocked storage).
 */
export function moveStorageKey(oldKey: string, newKey: string): void {
    try {
        const old = window.localStorage.getItem(oldKey);

        if (old === null) {
            return;
        }

        if (window.localStorage.getItem(newKey) === null) {
            window.localStorage.setItem(newKey, old);
        }

        window.localStorage.removeItem(oldKey);
    } catch {
        // Storage unavailable: nothing to move.
    }
}
