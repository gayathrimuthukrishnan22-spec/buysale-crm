const CACHE_NAME = 'property-crm-v1';
const ASSETS_TO_CACHE = [
  'assets/css/style.css',
  'assets/js/main.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS_TO_CACHE)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Network-first for HTML/API (always fresh data), cache-first for static assets
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.pathname.match(/\.(css|js|png|jpg|svg|woff2?)$/)) {
    event.respondWith(
      caches.match(event.request).then((cached) => cached || fetch(event.request))
    );
  }
  // All other requests (PHP pages, API calls) go straight to network - no caching of dynamic/sensitive data
});
