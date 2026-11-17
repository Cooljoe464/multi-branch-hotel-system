const CACHE = 'hospitality-os-shell-v2';
const SHELL = ['/', '/favicon.svg', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names
                    .filter((name) => name.startsWith('hospitality-os-shell-') && name !== CACHE)
                    .map((name) => caches.delete(name)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'purge-offline-data') {
        event.waitUntil(
            caches.keys().then((names) => Promise.all(
                names
                    .filter((name) => name.startsWith('hospitality-os-shell-'))
                    .map((name) => caches.delete(name)),
            )),
        );
    }
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) {
        return;
    }

    const url = new URL(event.request.url);
    const isAppAsset = url.pathname.startsWith('/build/assets/');
    const isAppNavigation = event.request.mode === 'navigate';
    if (!isAppAsset && !isAppNavigation) {
        return;
    }

    event.respondWith(
        fetch(event.request).then((response) => {
            if (response.ok && (isAppAsset || url.pathname === '/')) {
                const copy = response.clone();
                event.waitUntil(caches.open(CACHE).then((cache) => cache.put(event.request, copy)));
            }

            return response;
        }).catch(async () => {
            const cached = await caches.match(isAppNavigation ? '/' : event.request);

            return cached ?? Response.error();
        }),
    );
});
