// =========================================================================
// AGENDOU!! - Service Worker (PWA Offline & Asset Caching v2.0)
// =========================================================================

const CACHE_NAME = 'agendou-cache-v3';
const STATIC_ASSETS = [
  '/app/agendou/public/css/landing.css',
  '/app/agendou/public/css/booking.css',
  '/app/agendou/public/css/admin.css',
  '/app/agendou/public/js/pwa-installer.js',
  '/app/agendou/public/icons/icon-192.png',
  '/app/agendou/public/icons/icon-512.png',
  '/app/agendou/public/icons/icon-maskable-192.png',
  '/app/agendou/public/icons/apple-touch-icon.png',
  '/app/agendou/public/icons/favicon-32x32.png',
  '/app/agendou/manifest.json'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('Alguns assets não puderam ser pré-cacheados:', err);
      });
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
            console.log('Removendo cache antigo:', key);
            return caches.delete(key);
          }
        })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  // Ignorar requisições não-GET (como POST de agendamentos, login ou conclusões)
  if (event.request.method !== 'GET') {
    return;
  }

  const url = new URL(event.request.url);

  // Ignorar chamadas da API do Google, endpoints de pagamento ou externas
  if (url.origin !== self.location.origin) {
    return;
  }

  // Requisições de navegação HTML (Network first, caindo de volta pro cache se offline)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          return response;
        })
        .catch(() => {
          return caches.match(event.request).then((cached) => {
            return cached || caches.match('/app/agendou/');
          });
        })
    );
    return;
  }

  // Scripts e Estilos (CSS, JS) -> Network First com fallback para cache
  if (url.pathname.match(/\.(css|js)$/)) {
    event.respondWith(
      fetch(event.request)
        .then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(event.request, responseClone);
            });
          }
          return networkResponse;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Imagens e Fontes estáticas -> Cache First com revalidação
  if (url.pathname.match(/\.(png|jpg|jpeg|svg|webp|ico|woff2|woff|ttf)$/)) {
    event.respondWith(
      caches.match(event.request).then((cached) => {
        const fetchPromise = fetch(event.request).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(event.request, responseClone);
            });
          }
          return networkResponse;
        }).catch(() => cached);

        return cached || fetchPromise;
      })
    );
    return;
  }

  // Padrão geral: busca na rede
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});
