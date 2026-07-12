/**
 * 📱 Service Worker - PWA Offline Support + Cache
 */

const CACHE_NAME = 'shopvivaliz-v1';
const RUNTIME_CACHE = 'shopvivaliz-runtime-v1';
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/public/assets/style.css',
  '/public/assets/main.js',
  '/public/assets/logo-192.png',
  '/offline.html'
];

// Install event
self.addEventListener('install', event => {
  console.log('Service Worker installing...');

  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => self.skipWaiting())
  );
});

// Activate event
self.addEventListener('activate', event => {
  console.log('Service Worker activating...');

  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME && cacheName !== RUNTIME_CACHE) {
            console.log('Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event - Cache first, fallback to network
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Skip external requests
  if (url.origin !== location.origin) {
    return;
  }

  // Static assets - cache first
  if (isStaticAsset(url.pathname)) {
    return event.respondWith(
      caches.match(request)
        .then(response => response || fetch(request))
        .then(response => {
          if (!response) return caches.match('/offline.html');

          // Clone and cache
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, clone));

          return response;
        })
        .catch(() => caches.match('/offline.html'))
    );
  }

  // HTML pages - network first
  if (request.mode === 'navigate' || url.pathname.endsWith('.php')) {
    return event.respondWith(
      fetch(request)
        .then(response => {
          if (!response || response.status !== 200) {
            return caches.match(request) || caches.match('/offline.html');
          }

          // Cache successful responses
          const clone = response.clone();
          caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));

          return response;
        })
        .catch(() => {
          return caches.match(request) || caches.match('/offline.html');
        })
    );
  }

  // API calls - network first with cache fallback
  if (url.pathname.startsWith('/api/')) {
    return event.respondWith(
      fetch(request)
        .then(response => {
          const clone = response.clone();
          caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
          return response;
        })
        .catch(() => caches.match(request))
    );
  }
});

// Background sync for offline actions
self.addEventListener('sync', event => {
  if (event.tag === 'sync-cart') {
    event.waitUntil(syncCart());
  }
});

// Push notifications
self.addEventListener('push', event => {
  if (!event.data) return;

  const data = event.data.json();
  const options = {
    body: data.body || 'Notificação de ShopVivaliz',
    icon: '/assets/logo-192.png',
    badge: '/assets/badge-72.png',
    tag: data.tag || 'notification',
    requireInteraction: false,
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'ShopVivaliz', options)
  );
});

// Notification click
self.addEventListener('notificationclick', event => {
  event.notification.close();

  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(clientList => {
      // Se já tem janela aberta, focar nela
      for (let i = 0; i < clientList.length; i++) {
        if (clientList[i].url === event.notification.data.url && 'focus' in clientList[i]) {
          return clientList[i].focus();
        }
      }

      // Senão, abrir nova
      if (clients.openWindow) {
        return clients.openWindow(event.notification.data.url || '/');
      }
    })
  );
});

// Helpers
function isStaticAsset(pathname) {
  return /\.(js|css|png|jpg|jpeg|svg|woff2?|ttf|eot)$/i.test(pathname);
}

async function syncCart() {
  try {
    const cart = await getFromIndexDB('cart');
    if (!cart || cart.length === 0) return;

    const response = await fetch('/api/cart/sync', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ items: cart })
    });

    if (response.ok) {
      await clearFromIndexDB('cart');
    }
  } catch (error) {
    console.error('Sync cart failed:', error);
  }
}

// IndexDB helpers
async function getFromIndexDB(storeName) {
  return new Promise((resolve, reject) => {
    const db = indexedDB.open('shopvivaliz', 1);
    db.onsuccess = () => {
      const transaction = db.result.transaction(storeName, 'readonly');
      const store = transaction.objectStore(storeName);
      const request = store.getAll();
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    };
    db.onerror = () => reject(db.error);
  });
}

async function clearFromIndexDB(storeName) {
  return new Promise((resolve, reject) => {
    const db = indexedDB.open('shopvivaliz', 1);
    db.onsuccess = () => {
      const transaction = db.result.transaction(storeName, 'readwrite');
      const store = transaction.objectStore(storeName);
      const request = store.clear();
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    };
    db.onerror = () => reject(db.error);
  });
}
