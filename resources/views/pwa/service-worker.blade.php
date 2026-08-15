/**
 * Service worker for the three client apps.
 *
 * Deliberately conservative about what it caches:
 *
 *  - The app shell and static assets are cached so the app opens instantly and
 *    survives a dead connection in a tunnel or a lift.
 *  - API responses are NEVER served from cache. A stale bus position or, far
 *    worse, a stale wallet balance is actively misleading — offline, the app
 *    must say it is offline rather than show yesterday's money.
 */

const VERSION = '{{ config("app.version", "1") }}-{{ app()->environment() }}';
const SHELL_CACHE = `hamsafar-shell-${VERSION}`;
const ASSET_CACHE = `hamsafar-assets-${VERSION}`;

const SHELL_URLS = [
    '/app/passenger',
    '/app/driver',
    '/app/merchant',
    '/offline',
    '/fonts/Vazirmatn-Regular.woff2',
    '/fonts/Vazirmatn-SemiBold.woff2',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll(SHELL_URLS))
            .then(() => self.skipWaiting())
            .catch(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== SHELL_CACHE && key !== ASSET_CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // Never cache the API. Money and live positions must be fresh or absent.
    if (url.pathname.startsWith('/api/')) return;

    // Build output is content-hashed, so cache-first is safe and fast.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                const copy = response.clone();
                caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));

                return response;
            })),
        );

        return;
    }

    // Navigations: network first, cached shell as the fallback.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(SHELL_CACHE).then((cache) => cache.put(request, copy));

                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached || caches.match('/offline'))),
        );
    }
});

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload;
    try {
        payload = event.data.json();
    } catch {
        return;
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || @json(__('common.app_name')), {
            body: payload.body,
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            dir: 'rtl',
            lang: 'fa',
            tag: payload.tag,
            data: { url: payload.url || '/app/passenger' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const target = event.notification.data?.url || '/app/passenger';
            const existing = clients.find((client) => client.url.includes(target));

            return existing ? existing.focus() : self.clients.openWindow(target);
        }),
    );
});
