// ========================================================
// AGENDOU - Service Worker (Offline Cache & PWA)
// ========================================================

const CACHE_NAME = 'agendou-cache-v1';
const ASSETS_TO_CACHE = [
  '/app/agendou/public/css/landing.css',
  '/app/agendou/public/css/booking.css',
  '/app/agendou/public/css/admin.css'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE).catch(() => {});
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  // Network first, falling back to cache
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});
