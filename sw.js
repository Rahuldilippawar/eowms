// EOWMS Service Worker
// This app is data/database-driven, so full offline functionality isn't
// meaningful — the goals here are simply: (1) make the app installable
// (Add to Home Screen) and (2) show a friendly message instead of the
// browser's default error page if someone opens it with no signal.

const CACHE_NAME = 'eowms-shell-v5';
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

// ==================== Push Notification (Mobile App सारखी — App बंद असतानाही) ====================
// - एकाच विषयाच्या सूचना एकत्र (tag) पण नवीन आली की पुन्हा वाजते (renotify)
// - SOS / तातडीची — वापरकर्ता बंद करेपर्यंत Screen वर राहते (requireInteraction)
// - App-Icon वर न वाचलेल्यांचा आकडा (Badging API)
// - "उघडा" / "वाचलं" बटणं; Click → आधीच उघडं असलेलं App त्या पानावर, नाहीतर नवं
const ICON = 'assets/icons/icon-192.png';
const BADGE = 'assets/icons/badge-96.png';

function setBadge(n) {
  try {
    if (!('setAppBadge' in self.navigator)) return;
    if (n > 0) self.navigator.setAppBadge(n); else self.navigator.clearAppBadge();
  } catch (e) {}
}

self.addEventListener('push', (event) => {
  let data = { title: 'E-Admin', body: 'नवीन सूचना', url: 'notifications/index.php' };
  try {
    if (event.data) { data = Object.assign(data, event.data.json()); }
  } catch (e) {
    try { if (event.data) data.body = event.data.text(); } catch (e2) {}
  }
  const urgent = !!data.urgent;
  const opts = {
    body: data.body || '',
    icon: data.icon || ICON,
    badge: BADGE,
    tag: data.tag || ('eowms-' + Date.now()),
    renotify: true,
    timestamp: data.ts || Date.now(),
    requireInteraction: urgent,
    silent: false,
    vibrate: urgent ? [400, 150, 400, 150, 400, 150, 800] : [200, 100, 200],
    data: { url: data.url || 'notifications/index.php', nid: data.nid || 0 },
    actions: [
      { action: 'open', title: 'उघडा' },
      { action: 'read', title: '✓ वाचलं' },
    ],
  };
  event.waitUntil(Promise.all([
    self.registration.showNotification(data.title || 'E-Admin', opts),
    typeof data.unread === 'number' ? setBadge(data.unread) : null,
    // उघड्या Tabs ना कळवा — Bell चा आकडा लगेच बदलावा
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((cl) => cl.forEach((c) => c.postMessage({ type: 'eowms-push', unread: data.unread }))),
  ]));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const d = event.notification.data || {};
  const scope = self.registration.scope;
  const target = new URL(d.url || 'notifications/index.php', scope).href;

  if (event.action === 'read') {
    // फक्त "वाचलं" — App न उघडता (notifications/open.php वाचलं म्हणून नोंदवतं)
    event.waitUntil(fetch(target, { credentials: 'include', redirect: 'manual' }).catch(() => {}));
    return;
  }
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // आधी त्याच पानावरचा Tab, नाहीतर App चा कोणताही Tab — त्यालाच Focus करून त्या पानावर
      for (const client of clientList) {
        if (client.url === target && 'focus' in client) return client.focus();
      }
      for (const client of clientList) {
        if (client.url.indexOf(scope) === 0 && 'focus' in client) {
          return client.focus().then((c) => (c && 'navigate' in c ? c.navigate(target) : null)).catch(() => self.clients.openWindow(target));
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(target) : null;
    })
  );
});

// Browser ने Subscription बदलली (उदा. Key Rotate) — लगेच नवी Subscription घेतो
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    fetch(new URL('push-vapid-key.php', self.registration.scope), { credentials: 'include' })
      .then((r) => r.json())
      .then((k) => {
        if (!k.publicKey) return;
        const pad = '='.repeat((4 - k.publicKey.length % 4) % 4);
        const raw = atob((k.publicKey + pad).replace(/-/g, '+').replace(/_/g, '/'));
        const key = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) key[i] = raw.charCodeAt(i);
        return self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      })
      // Server वर नोंद — App पुढच्या वेळी उघडल्यावर footer.php मधून (POST साठी CSRF Token लागतो)
      .catch(() => {})
  );
});
