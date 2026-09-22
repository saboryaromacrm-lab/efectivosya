// Service Worker para Caja SYA - PWA
// Subir la versión invalida el cache viejo y fuerza a bajar el index.html nuevo.
const CACHE_NAME = 'caja-sya-v4';
const CACHE_URLS = [
  './',
  './index.html',
  './manifest.json',
  './icons/icon-192.png',
  './icons/icon-512.png',
  'https://cdn.jsdelivr.net/npm/chart.js'
];

// Instalación del Service Worker
self.addEventListener('install', (event) => {
  console.log('[SW] Instalando Service Worker...');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('[SW] Cacheando archivos estáticos');
        return cache.addAll(CACHE_URLS);
      })
      .then(() => {
        console.log('[SW] Instalación completada');
        return self.skipWaiting();
      })
      .catch((error) => {
        console.error('[SW] Error en instalación:', error);
      })
  );
});

// Activación del Service Worker
self.addEventListener('activate', (event) => {
  console.log('[SW] Activando Service Worker...');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          // Eliminar caches viejos
          if (cacheName !== CACHE_NAME) {
            console.log('[SW] Eliminando cache viejo:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      console.log('[SW] Activación completada');
      return self.clients.claim();
    })
  );
});

// Estrategia de fetch: Network First, fallback to Cache
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  
  // Para peticiones a la API, siempre usar la red
  if (url.pathname.includes('api.php')) {
    event.respondWith(
      fetch(event.request)
        .catch(() => {
          // Si no hay conexión, devolver error JSON
          return new Response(
            JSON.stringify({ error: 'Sin conexión a internet' }),
            { 
              status: 503, 
              headers: { 'Content-Type': 'application/json' }
            }
          );
        })
    );
    return;
  }
  
  // Para otros recursos: Network first, fallback to cache
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Si la respuesta es válida, guardarla en cache
        if (response && response.status === 200) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseClone);
          });
        }
        return response;
      })
      .catch(() => {
        // Si falla la red, buscar en cache
        return caches.match(event.request)
          .then((response) => {
            if (response) {
              return response;
            }
            // Si no está en cache y es una página, devolver index.html
            if (event.request.mode === 'navigate') {
              return caches.match('./index.html');
            }
            return new Response('Recurso no disponible offline', { status: 503 });
          });
      })
  );
});

// Escuchar mensajes del cliente
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

console.log('[SW] Service Worker cargado');
