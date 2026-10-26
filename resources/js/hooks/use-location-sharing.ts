import { useEffect, useRef, useState } from 'react';
import DriverController from '@/actions/App/Http/Controllers/DriverController';
import { postJson } from '@/lib/json';

export type LocationSharing =
    | { state: 'off' }
    | { state: 'unsupported' }
    | { state: 'asking' }
    | { state: 'denied' }
    | { state: 'sharing'; lastSentAt: Date | null; accuracy: number | null };

/** Send at most this often while moving… */
const MIN_INTERVAL_MS = 30_000;
/** …and at least this often while parked at a stop. */
const HEARTBEAT_MS = 120_000;
/** Movement worth sending straight away. */
const MIN_MOVE_M = 150;

function metresBetween(a: GeolocationCoordinates, b: GeolocationCoordinates) {
    const r = 6_371_000;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(b.latitude - a.latitude);
    const dLng = toRad(b.longitude - a.longitude);
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(a.latitude)) *
            Math.cos(toRad(b.latitude)) *
            Math.sin(dLng / 2) ** 2;

    return 2 * r * Math.asin(Math.sqrt(h));
}

/**
 * Shares the driver's position with the dispatch board while their run is
 * open. Browsers only report location while the page is on screen, so
 * this pauses when the phone locks or another app is in front.
 */
export function useLocationSharing(enabled: boolean): LocationSharing {
    const [status, setStatus] = useState<LocationSharing>({ state: 'off' });
    const last = useRef<{ coords: GeolocationCoordinates; at: number } | null>(
        null,
    );
    const latest = useRef<GeolocationCoordinates | null>(null);

    useEffect(() => {
        if (!enabled) {
            setStatus({ state: 'off' });

            return;
        }

        if (!('geolocation' in navigator)) {
            setStatus({ state: 'unsupported' });

            return;
        }

        setStatus({ state: 'asking' });

        const send = (coords: GeolocationCoordinates) => {
            last.current = { coords, at: Date.now() };
            void postJson(DriverController.locate.url(), {
                latitude: coords.latitude,
                longitude: coords.longitude,
                accuracy: coords.accuracy,
                speed: coords.speed,
            })
                .then((response) => {
                    if (response.ok) {
                        setStatus({
                            state: 'sharing',
                            lastSentAt: new Date(),
                            accuracy: Math.round(coords.accuracy),
                        });
                    }
                })
                .catch(() => {
                    // Offline in a dead zone: the next position will go.
                    last.current = null;
                });
        };

        const watch = navigator.geolocation.watchPosition(
            (position) => {
                latest.current = position.coords;
                const previous = last.current;

                if (
                    previous === null ||
                    Date.now() - previous.at >= MIN_INTERVAL_MS ||
                    metresBetween(previous.coords, position.coords) >=
                        MIN_MOVE_M
                ) {
                    send(position.coords);
                } else {
                    setStatus((s) =>
                        s.state === 'sharing'
                            ? s
                            : {
                                  state: 'sharing',
                                  lastSentAt: null,
                                  accuracy: Math.round(
                                      position.coords.accuracy,
                                  ),
                              },
                    );
                }
            },
            (error) => {
                setStatus(
                    error.code === error.PERMISSION_DENIED
                        ? { state: 'denied' }
                        : { state: 'asking' },
                );
            },
            { enableHighAccuracy: true, maximumAge: 15_000, timeout: 30_000 },
        );

        // Parked at a drop the phone may not report anything new.
        const heartbeat = window.setInterval(() => {
            if (
                latest.current !== null &&
                document.visibilityState === 'visible' &&
                (last.current === null ||
                    Date.now() - last.current.at >= HEARTBEAT_MS)
            ) {
                send(latest.current);
            }
        }, HEARTBEAT_MS / 4);

        return () => {
            navigator.geolocation.clearWatch(watch);
            window.clearInterval(heartbeat);
        };
    }, [enabled]);

    return status;
}
