/* Service worker — static-asset cache for the PWA (Prompt 19).
 *
 * Rules (docs/frontend/pwa.md):
 * - Only same-origin GET static files are cached; /api/v1/* and /uploads/*
 *   are NEVER intercepted or stored, so no personal data enters Cache
 *   Storage and no stale response can overwrite newer server data.
 * - Navigations: cache-first shell with background revalidate (Prompt 26 —
 *   instant paint from cache, fresh HTML fetched alongside).
 * - CSS/JS/icons: stale-while-revalidate (fast paint, background refresh).
 * - Mutations are never queued here — offline writes fail fast in the UI.
 * - Bump CACHE when the precache list or shell changes.
 */

const CACHE = 'rafeeq-static-v8';

// Prompt 26: only icon-192 is precached. The 512/maskable/apple-touch icons
// are fetched on demand at OS-install time via the manifest — caching them
// cost ~540 KB of install weight for zero runtime benefit.
const PRECACHE = [
  '/',
  '/manifest.json',
  '/assets/icons/icon-192.png',
  '/css/base.css',
  '/css/tokens.css',
  '/css/components/alerts.css',
  '/css/components/bottom-sheet.css',
  '/css/components/buttons.css',
  '/css/components/card.css',
  '/css/components/flip-cards.css',
  '/css/components/forms.css',
  '/css/components/modal.css',
  '/css/components/navigation.css',
  '/css/components/progress-bar.css',
  '/css/components/quran-cards.css',
  '/css/components/states.css',
  '/css/components/task-list.css',
  '/css/components/toast.css',
  '/css/pages/dashboard.css',
  '/css/pages/login.css',
  '/css/pages/revision.css',
  '/js/components/bottom-sheet.js',
  '/js/components/dialog-focus.js',
  '/js/components/form-errors.js',
  '/js/components/modal.js',
  '/js/components/notifications.js',
  '/js/components/progress-bar.js',
  '/js/components/toast.js',
  '/js/core/api-client.js',
  '/js/core/app.js',
  '/js/core/auth.js',
  '/js/core/dom.js',
  '/js/core/prefs.js',
  '/js/core/pwa.js',
  '/js/core/router.js',
  '/js/core/routes.js',
  '/js/core/i18n.js',
  '/js/core/state.js',
  '/js/features/dashboard/controls.js',
  '/js/features/dashboard/dashboard-page.js',
  '/js/features/flip-cards/flag-card-sheet.js',
  '/js/features/flip-cards/flip-cards-api.js',
  '/js/features/flip-cards/flip-cards-page.js',
  '/js/features/progress/progress-api.js',
  '/js/features/reminders/reminders.js',
  '/js/features/revision/revision-api.js',
  '/js/features/revision/session-page.js',
  '/js/features/ribat/memorization-api.js',
  '/js/features/ribat/rabt-page.js',
  '/js/features/ribat/memorize-sheet.js',
  '/js/features/settings/settings-api.js',
  '/js/features/settings/settings-sheet.js',
  '/js/features/settings/settings-sync.js',
  '/js/features/tasks/task-create.js',
  '/js/features/tasks/task-list.js',
  '/js/features/tasks/tasks-api.js',
  '/js/pages/login-page.js',
  '/js/pages/register-page.js',
  '/js/pages/soon-page.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

/** Reminder/notification clicks (Prompt 21): focus an open window, otherwise
 *  open the app; always dismiss the notification. */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if ('focus' in client) {
          return client.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow('/') : undefined;
    })
  );
});

/** Cache-first shell with background revalidate (Prompt 26): paint from
 *  cache instantly, refresh the stored copy alongside. Offline the cached
 *  shell is the answer; with an empty cache we fall through to the network. */
async function navigationResponse(request, event) {
  const cache = await caches.open(CACHE);
  const cached =
    (await cache.match(request, { ignoreSearch: true })) ?? (await cache.match('/'));

  const refresh = fetch(request)
    .then((fresh) => {
      if (fresh.ok) {
        return cache.put('/', fresh.clone()).then(() => fresh);
      }
      return fresh;
    })
    .catch(() => null);

  if (cached) {
    event.waitUntil(refresh.then(() => undefined));
    return cached;
  }

  const response = await refresh;
  return response ?? Response.error();
}

/** Stale-while-revalidate for static assets: instant paint, silent refresh. */
async function assetResponse(request, event) {
  const cache = await caches.open(CACHE);
  const cached = await cache.match(request);

  const refresh = fetch(request)
    .then((response) => {
      if (response.ok && response.status === 200) {
        return cache.put(request, response.clone()).then(() => response);
      }
      return response;
    })
    .catch(() => null);

  if (cached) {
    event.waitUntil(refresh.then((response) => response !== null));
    return cached;
  }

  const response = await refresh;
  return response ?? Response.error();
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  // API responses and uploads hold personal data — network only, never stored.
  if (url.pathname.startsWith('/api/v1/') || url.pathname.startsWith('/uploads/')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(navigationResponse(request, event));
    return;
  }

  event.respondWith(assetResponse(request, event));
});
