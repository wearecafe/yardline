/*
 * YardRunner service worker.
 *
 * - Pages always come from the network (they hold live orders, stock and
 *   prices). With no connection, a page load shows /offline.html instead of
 *   the browser's error.
 * - Built assets (/build/…, content-hashed) are cached on first use, so the
 *   app opens fast on weak yard Wi-Fi.
 * - Inertia visits, form posts and anything with data pass straight
 *   through. Nothing a person sees is stored here.
 * - Web push: shows alerts and opens the right screen when one is tapped.
 *
 * Bump VERSION when this file or the offline page changes.
 */
const VERSION = 'v1';
const SHELL_CACHE = `yardrunner-shell-${VERSION}`;
const ASSET_CACHE = 'yardrunner-assets';
const MAX_ASSETS = 150;
const OFFLINE_URL = '/offline.html';
const SHELL = [
    OFFLINE_URL,
    '/favicon.svg',
    '/icons/icon-192.png',
    '/icons/badge-96.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) =>
                cache.addAll(
                    SHELL.map((url) => new Request(url, { cache: 'reload' })),
                ),
            ),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keep = [SHELL_CACHE, ASSET_CACHE];
            const names = await caches.keys();

            await Promise.all(
                names
                    .filter(
                        (name) =>
                            name.startsWith('yardrunner-') &&
                            !keep.includes(name),
                    )
                    .map((name) => caches.delete(name)),
            );

            if (self.registration.navigationPreload) {
                await self.registration.navigationPreload.enable();
            }

            await self.clients.claim();
        })(),
    );
});

// The page asks for a waiting update to take over (the "Reload" toast).
self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(page(event));

        return;
    }

    // Inertia visits carry data for one person: never from a cache.
    if (request.headers.has('X-Inertia')) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(asset(request));

        return;
    }

    if (SHELL.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((hit) => hit ?? fetch(request)),
        );
    }
});

async function page(event) {
    try {
        const preloaded = await event.preloadResponse;

        return preloaded ?? (await fetch(event.request));
    } catch {
        const offline = await caches.match(OFFLINE_URL);

        return offline ?? Response.error();
    }
}

async function asset(request) {
    const cache = await caches.open(ASSET_CACHE);
    const hit = await cache.match(request);

    if (hit) {
        return hit;
    }

    const response = await fetch(request);

    if (response.ok && response.type === 'basic') {
        await cache.put(request, response.clone());
        trim(cache);
    }

    return response;
}

async function trim(cache) {
    const keys = await cache.keys();

    for (const key of keys.slice(0, Math.max(0, keys.length - MAX_ASSETS))) {
        await cache.delete(key);
    }
}

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'YardRunner';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: data.body || '',
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-96.png',
            tag: data.tag || undefined,
            renotify: Boolean(data.tag),
            data: { url: data.url || '/start' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(
        event.notification.data?.url || '/start',
        self.location.origin,
    ).href;

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            // Reuse an open YardRunner window rather than stacking new ones.
            for (const client of windows) {
                if (
                    new URL(client.url).origin === self.location.origin &&
                    'navigate' in client
                ) {
                    try {
                        await client.focus();

                        return await client.navigate(target);
                    } catch {
                        // A window this worker doesn't control can't be steered.
                        break;
                    }
                }
            }

            return self.clients.openWindow(target);
        })(),
    );
});
