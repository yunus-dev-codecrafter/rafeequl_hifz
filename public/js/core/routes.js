/** The route table (hash paths); app.js owns boot, header and logout wiring.
 *
 * Prompt 26: every page module is loaded with a dynamic import() inside its
 * route handler, so the initial boot graph only contains core + the first
 * route's chunk instead of all the page trees.
 * The router stays synchronous (handler() fire-and-forget); the nav token
 * drops stale chunks when a newer navigation started while one was loading.
 */

import { route, setFallback, navigate } from './router.js';
import { isAuthenticated, requireSession } from './auth.js';
import { byId } from './dom.js';

let navToken = 0;

/** Increments the navigation token; returns a guard that reports whether a
 *  newer navigation superseded this one while its chunk was loading. */
function newNavigation() {
  const token = ++navToken;
  return () => token !== navToken;
}

export function registerRoutes() {
  route('/', async () => {
    if (!requireSession()) {
      return;
    }
    const stale = newNavigation();
    const { renderDashboard } = await import('../features/dashboard/dashboard-page.js');
    if (stale()) {
      return;
    }
    renderDashboard(byId('app'));
  });

  route('/login', async () => {
    if (isAuthenticated()) {
      navigate('/');
      return;
    }
    const stale = newNavigation();
    const { renderLogin } = await import('../pages/login-page.js');
    if (stale()) {
      return;
    }
    renderLogin(byId('app'));
  });

  route('/register', async () => {
    if (isAuthenticated()) {
      navigate('/');
      return;
    }
    const stale = newNavigation();
    const { renderRegister } = await import('../pages/register-page.js');
    if (stale()) {
      return;
    }
    renderRegister(byId('app'));
  });

  route('/revision', async () => {
    if (!requireSession()) {
      return;
    }
    const stale = newNavigation();
    const { renderRevision } = await import('../features/revision/session-page.js');
    if (stale()) {
      return;
    }
    renderRevision(byId('app'));
  });

  route('/rabt', async () => {
    if (!requireSession()) {
      return;
    }
    const stale = newNavigation();
    const { renderRabtPage } = await import('../features/ribat/rabt-page.js');
    if (stale()) {
      return;
    }
    renderRabtPage(byId('app'));
  });

  route('/flip-cards', async () => {
    if (!requireSession()) {
      return;
    }
    const stale = newNavigation();
    const { renderFlipCards } = await import('../features/flip-cards/flip-cards-page.js');
    if (stale()) {
      return;
    }
    renderFlipCards(byId('app'));
  });

  setFallback(() => navigate(isAuthenticated() ? '/' : '/login'));
}
