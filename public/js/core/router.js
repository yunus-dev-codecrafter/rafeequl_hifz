/** Hash-based router: paths live after '#', deep links never hit the server. */

const handlers = new Map();
let fallbackHandler = null;

export function route(path, handler) {
  handlers.set(path, handler);
}

export function setFallback(handler) {
  fallbackHandler = handler;
}

export function navigate(path) {
  const target = '#' + path;
  if (window.location.hash === target) {
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  } else {
    window.location.hash = target;
  }
}

export function currentPath() {
  const raw = window.location.hash.slice(1) || '/';
  return raw.split('?')[0];
}

export function startRouter() {
  window.addEventListener('hashchange', () => {
    dispatch();
    applyRouteA11y();
    // SPA accessibility: after a real navigation, move focus to the new view.
    const main = document.getElementById('app');
    if (main) {
      main.focus();
    }
  });
  dispatch();
  applyRouteA11y();
}

/** Marks the header link for the active route (`aria-current="page"`). */
function applyRouteA11y() {
  const path = currentPath();
  document.querySelectorAll('.app-header a[href^="#/"]').forEach((link) => {
    if (link.getAttribute('href') === '#' + path) {
      link.setAttribute('aria-current', 'page');
    } else {
      link.removeAttribute('aria-current');
    }
  });
}

export function dispatch() {
  const path = currentPath();
  const handler = handlers.get(path) ?? fallbackHandler;
  if (handler) {
    handler(path);
  }
}
