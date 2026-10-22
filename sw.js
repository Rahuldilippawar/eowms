// EOWMS Service Worker
// This app is data/database-driven, so full offline functionality isn't
// meaningful — the goals here are simply: (1) make the app installable
// (Add to Home Screen) and (2) show a friendly message instead of the
// browser's default error page if someone opens it with no signal.

const CACHE_NAME = 'eowms-shell-v3';
const APP_SHELL = [
  'assets/css/eowms.css',
  'offline.html',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only handle simple GET navigations/assets; let everything else
  // (POST forms, API-style calls) go straight to the network untouched —
  // this is a live, database-driven system, not something to cache.
  if (req.method !== 'GET') return;

  // Page navigations: try the network first (fresh data always wins);
  // fall back to the offline page only if there's truly no connection.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('offline.html'))
    );
    return;
  }

  // Static shell assets (CSS/icons): cache-first for speed, network fallback.
  if (APP_SHELL.some((asset) => req.url.includes(asset))) {
    event.respondWith(
      caches.match(req).then((cached) => cached || fetch(req))
    );
  }
});

// ==================== Push Notification (App बंद असतानाही) ====================
self.addEventListener('push', (event) => {
  let data = { title: 'EOWMS', body: 'नवीन सूचना', url: '/' };
  try {
    if (event.data) { data = Object.assign(data, event.data.json()); }
  } catch (e) {}

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: 'assets/icons/icon-192.png',
      badge: 'assets/icons/icon-192.png',
      data: { url: data.url || '/' },
      vibrate: [200, 100, 200],
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.includes(targetUrl) && 'focus' in client) { return client.focus(); }
      }
      if (self.clients.openWindow) { return self.clients.openWindow(targetUrl); }
    })
  );
});

