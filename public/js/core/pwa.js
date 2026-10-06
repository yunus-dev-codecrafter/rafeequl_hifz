/** PWA registration + offline awareness (Prompt 19).
 *
 * The service worker (public/sw.js) owns caching; this module owns the
 * registration lifecycle, the update-available toast and the offline
 * banner. It never queues writes — offline mutations fail fast in the
 * feature code, so stale local state can never overwrite the server. */

import { byId } from './dom.js';
import { notify } from '../components/notifications.js';

let updateAnnounced = false;

function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js', { scope: '/' })
      .then((registration) => {
        registration.addEventListener('updatefound', () => {
          const worker = registration.installing;
          if (worker === null) {
            return;
          }
          worker.addEventListener('statechange', () => {
            // A new worker finished downloading: tell the user once. The
            // update applies on the next reload — never force one mid-session.
            if (worker.state === 'installed' && navigator.serviceWorker.controller && !updateAnnounced) {
              updateAnnounced = true;
              notify.info('يتوفر تحديث جديد للتطبيق — أعد تحميل الصفحة لتطبيقه');
            }
          });
        });
      })
      .catch(() => {
        // Registration failure (unsupported/insecure): the app still works.
      });
  });
}

function applyOnlineState() {
  const online = navigator.onLine !== false;
  const banner = byId('offline-banner');
  if (banner !== null) {
    banner.hidden = online;
  }
  if (online) {
    delete document.documentElement.dataset.offline;
  } else {
    document.documentElement.dataset.offline = '';
  }
}

/** True when the browser reports no connectivity (fail-fast hints). */
export function isOffline() {
  return typeof navigator !== 'undefined' && navigator.onLine === false;
}

/** Call once at boot: SW registration + online/offline banner wiring. */
export function initPwa() {
  registerServiceWorker();

  applyOnlineState();

  window.addEventListener('offline', () => {
    applyOnlineState();
  });

  window.addEventListener('online', () => {
    applyOnlineState();
    notify.success('عاد الاتصال بالإنترنت');
  });
}
