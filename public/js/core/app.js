/** Application initialization: preferences, route table, header (kept in sync
 *  with the session store), auth boot, router start. */

import { byId } from './dom.js';
import { startRouter, navigate } from './router.js';
import { fetchMe, isAuthenticated, logout } from './auth.js';
import { appState } from './state.js';
import { initPrefs } from './prefs.js';
import { initI18n } from './i18n.js';
import { registerRoutes } from './routes.js';
import { notify } from '../components/notifications.js';
import { reconcileFromServer } from '../features/settings/settings-sync.js';
import { initReminders } from '../features/reminders/reminders.js';
import { initPwa, isOffline } from './pwa.js';

function updateHeader() {
  byId('app-header').hidden = !isAuthenticated();
}

async function boot() {
  // Locale first: <html lang/dir> and every [data-i18n] node must be right
  // before any route paints its template.
  initI18n();
  initPwa();
  initPrefs();
  initReminders();

  // The header follows the session store: login/logout anywhere re-syncs it.
  appState.subscribe(() => updateHeader());

  byId('logout-button').addEventListener('click', async () => {
    try {
      await logout();
    } finally {
      notify.info('تم تسجيل الخروج');
      navigate('/login');
    }
  });

  // Skip link: focus the main landmark without touching the route hash.
  byId('skip-link').addEventListener('click', (event) => {
    event.preventDefault();
    byId('app').focus();
  });

  registerRoutes();

  try {
    await fetchMe();
  } catch {
    notify.error(isOffline() ? 'أنت غير متصل بالإنترنت' : 'تعذر الاتصال بالخادم');
  }

  // Prompt 26: server settings reconcile is independent of routing — fire it
  // alongside the router instead of holding the first paint behind it.
  // reconcileFromServer never rejects (settings-sync.js catches everything).
  const reconcile = isAuthenticated() ? reconcileFromServer() : Promise.resolve();

  updateHeader();
  startRouter();

  await reconcile;
}

if (typeof document !== 'undefined') {
  boot();
}
